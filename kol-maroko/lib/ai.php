<?php
/**
 * עוזר הבינה המלאכותית (docs/spec.md, פרק 8) — Claude דרך בקשת HTTP ישירה ל-/v1/messages, כמו
 * recipes-app/lib/ai.php (הכלי נפרס ב-rsync בלי Composer, ולכן בלי ה-SDK).
 *
 * X1: העוזר עובד על קבצי הפרויקט (📁), לא על הטיוטה הישנה. הבינה מקבלת את הקבצים שבהקשר כמו שהם (עם תקרה), ומחזירה
 * רשימת עריכות במבנה קבוע (פלט מובנה לפי סכימה): {path, old, new} — החלפה של קטע מדויק שמופיע פעם אחת — או {path, create}.
 * אין מחיקה ואין שינוי שם. השרת מחשב את התוצאה בזיכרון ומחזיר diff לכל קובץ (במילים — "מה ישתנה" של N1); רק "החל"
 * כותב — דרך מנוע הקבצים (pf_put, גרסה אחת לכל קובץ, "✨ <הבקשה>") או words_apply. ההצעה נשמרת בשרת, ו"החל" מחיל
 * אותה ולא מה שהדפדפן שולח. קובץ שהשתנה מאז ההצעה — 409, ושום דבר לא נכתב.
 *
 * מכסה: טוקנים לחודש לכל חבר (הבעלים — בלי מכסה). כל קריאה מקבלת request_id לפני שהיא יוצאת ונרשמת גם בכשל —
 * כך הדיווח לתוסף התשלומים (payments/) יהיה פעם אחת בדיוק (reported_at).
 */

declare(strict_types=1);

require_once __DIR__ . '/draft.php';
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/members.php';
require_once __DIR__ . '/project.php';
require_once __DIR__ . '/lexicon.php';

const AI_MODEL = 'claude-opus-5-5';
const AI_MAX_TOKENS = 16000;
const AI_TIMEOUT = 150;
const AI_QUOTA_DEFAULT = 300_000;                       // טוקנים לחודש לחבר, עד שהבעלים קובע אחרת
function ai_endpoint(): string
{
    return defined('KOL_AI_ENDPOINT') ? KOL_AI_ENDPOINT : 'https://api.anthropic.com/v1/messages';
}

const AI_SYSTEM = <<<'TXT'
אתה עוזר עריכה בסטודיו "קול מרוקו" — עמוד אחד באתר מגרב (לקסיקון של מילים ממרוקו, עם הקלטות של דוברים). העמוד הוא פרויקט
של קבצים (HTML, CSS, JS, JSON), כמו כל אתר. העורך שולח לך קבצים ובקשה, ואתה מחזיר רשימת עריכות מדויקת — לא טקסט חופשי.

העריכות (edits):
- עריכה בקובץ קיים: {path, old, new} — old הוא קטע שמופיע בקובץ **בדיוק פעם אחת**, תו בתו (כולל רווחים וירידות שורה), ו-new
  מחליף אותו. כמה עריכות באותו קובץ — לפי הסדר, כל אחת על התוצאה של הקודמת. קטע קצר וייחודי עדיף על העתקת הקובץ כולו.
- קובץ חדש: {path, create} — התוכן המלא. נתיב יחסי לשורש הפרויקט, בלי .. ובלי / בהתחלה.
- אין מחיקה ואין שינוי שם של קבצים — את זה עושה אדם בממשק.

כללים:
- שנה רק מה שהתבקש. אל תשכתב קוד או טקסט שלא ביקשו לשנות, ואל תעצב מחדש (הזחה, מירכאות) מה שלא נגעת בו.
- כל כיתוב בעמוד הוא תוכן של אלמנט ב-HTML (לא ב-CSS ולא ב-JS). העמוד בעברית ומימין לשמאל; מילים במרוקאית, בערבית
  ובצרפתית — כפי שהן.
- data/words.json — טבלת המילים כקובץ: {topics: [{id, label}], items: [{id, word, meaning (HTML), topics: [id], audio}]}. מילה
  קיימת — לשמור את ה-id שלה; מילה חדשה — בלי id. audio — להשאיר כמו שקיבלת.
- תכונות data-kol-* ב-HTML (data-kol-list, data-kol-template, data-kol-longtext וכו') — מחוברות לנתונים; לא לשנות את שמן.
- אזור data-kol-longtext מגיע מעורך התמלילים — לא לערוך את התוכן שלו (שם עורכים בעורך התמלילים).
- אל תמציא עובדות על מילים, מקורות או דוברים. אם הבקשה דורשת מידע שאין בקבצים — כתוב זאת ב-summary, ואל תוסיף.
- אם אי אפשר לבצע — edits ריק, וההסבר ב-summary.
- summary: משפט או שניים בעברית — מה שינית, או למה לא.
TXT;

const AI_CONTEXT_MAX = 200 * 1024;                      // בייטים של קבצים לבקשה — עד שבעל האתר קובע אחרת (ai_context_max)
const AI_EDITS_MAX = 80;
const AI_PROP_TTL = 86400;                              // הצעה שלא הוחלה תוך יום — נמחקת

/** המודל: הגדרה של בעל האתר (ai_model), ואם אין — הקבוע. */
function ai_model(): string
{
    $m = (string)setting('ai_model', '');
    return preg_match('/^claude-[a-z0-9.-]{3,60}$/', $m) ? $m : AI_MODEL;
}

function ai_context_max(): int
{
    $n = (int)setting('ai_context_max', '0');
    return $n >= 1024 && $n <= 4 * 1024 * 1024 ? $n : AI_CONTEXT_MAX;
}

function ai_schema(): array
{
    $str = ['type' => 'string'];
    return [
        'type' => 'object',
        'properties' => [
            'summary' => $str,
            'edits' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['path' => $str, 'old' => $str, 'new' => $str, 'create' => $str],
                'required' => ['path'],
                'additionalProperties' => false,
            ]],
        ],
        'required' => ['summary', 'edits'],
        'additionalProperties' => false,
    ];
}

// ── מכסה ──────────────────────────────────────────────────

function ai_month_start(): string
{
    return gmdate('Y-m-01\T00:00:00\Z');
}

function ai_quota(array $user): ?int
{
    if ($user['role'] === 'owner') return null;
    $m = member_by_sub($user['sub']);
    if ($m && $m['ai_quota'] !== null) return (int)$m['ai_quota'];
    return (int)(setting('ai_quota_default') ?? AI_QUOTA_DEFAULT);
}

function ai_used(string $sub): int
{
    $st = db()->prepare("SELECT COALESCE(SUM(input_tokens + output_tokens), 0) FROM ai_usage WHERE sub = ? AND at >= ? AND status <> 'error'");
    $st->execute([$sub, ai_month_start()]);
    return (int)$st->fetchColumn();
}

function ai_status(array $user): array
{
    $q = ai_quota($user);
    return ['ready' => secret_get('anthropic_api_key') !== null && function_exists('curl_init'), 'quota' => $q, 'used' => ai_used($user['sub']), 'model' => ai_model(), 'context_max' => ai_context_max()];
}

// ── הקריאה ────────────────────────────────────────────────

function ai_call(array $body, string $key): array
{
    $ch = curl_init(ai_endpoint());
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => AI_TIMEOUT, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01',
            'anthropic-beta: server-side-fallback-2026-07-01'],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($raw === false || $errno) throw new ApiError('לא הצלחתי להגיע לשירות הבינה (' . ($errno === CURLE_OPERATION_TIMEDOUT ? 'פג הזמן' : 'שגיאת רשת') . ')', 502);
    $data = json_decode((string)$raw, true);
    if ($status === 401 || $status === 403) throw new ApiError('מפתח ה-API נדחה — לבדוק אותו בהגדרות', 502);
    if ($status === 429) throw new ApiError('שירות הבינה עמוס או שהמכסה של החשבון נגמרה. נסו שוב בעוד דקה', 503);
    if ($status >= 500) throw new ApiError("שירות הבינה לא זמין כרגע ($status)", 503);
    if ($status !== 200 || !is_array($data)) {
        $m = is_array($data) ? (string)($data['error']['message'] ?? '') : '';
        throw new ApiError("שירות הבינה החזיר שגיאה ($status)" . ($m !== '' ? ': ' . mb_substr($m, 0, 160) : ''), 502);
    }
    return $data;
}

// ── עוזר הבינה על קבצי הפרויקט (פרוסה X1 §2.1) ─────────────

/** הסטודיו הישן (aiSheet ב-studio.js) עד M6: העוזר עבר לקבצים. */
function ai_suggest(array $user, array $b): array
{
    throw new ApiError('העוזר עבר ל-📁 פרויקט: פותחים קובץ ולוחצים ✨ (על המילים — ב-📚)', 410, ['moved' => 'project']);
}

/** קובץ טקסט שהעוזר רשאי לראות ולכתוב: בתוך הפרויקט, לא media/, לא בינארי. מחזיר [נתיב, סוג, תוכן, rev] או שגיאה. */
function ai_file(string $path): array
{
    $path = pf_path($path);
    if (pf_key($path) === 'media' || str_starts_with(pf_key($path), 'media/')) throw new ApiError("\"$path\" — קבצי מדיה אינם בידי העוזר");
    if ($path === pf_words_path()) { $json = words_json(); return [$path, 'words', $json, words_rev($json)]; }
    $r = pf_by_path($path);
    if (!$r) throw new ApiError("\"$path\" לא נמצא בפרויקט", 404);
    if ($r['kind'] !== 'file') throw new ApiError("\"$path\" אינו קובץ טקסט");
    $s = pf_blob_get($r['sha']);
    if (!mb_check_encoding($s, 'UTF-8') || str_contains($s, "\0")) throw new ApiError("\"$path\" הוא קובץ בינארי — העוזר עובד רק על טקסט");
    return [$path, 'file', $s, (int)$r['rev']];
}

/** קובצי מערכת לפי kol.json (system) — מותר לערוך, ומסומן (02 §6). */
function ai_system_files(): array
{
    $c = pf_kol()['config'] ?? [];
    return is_array($c['system'] ?? null) ? array_keys($c['system']) : ['js/kol-data.js'];
}

/**
 * ההקשר מהבקשה: paths[] (קבצים פתוחים / נבחרים), range {path, from, to} (שורות), element {path, element} (מסלול מהעורך
 * החזותי). מחזיר [קבצים: path → [סוג, תוכן, rev], הודעת ההקשר לבינה]. מעל התקרה — "צמצם את ההקשר".
 */
function ai_context(array $b): array
{
    $paths = array_values(array_filter((array)($b['paths'] ?? []), 'is_string'));
    $range = is_array($b['range'] ?? null) ? $b['range'] : null;
    $el = is_array($b['element'] ?? null) ? $b['element'] : null;
    if ($range && is_string($range['path'] ?? null)) $paths[] = $range['path'];
    if ($el && is_string($el['path'] ?? null)) $paths[] = $el['path'];
    $paths = array_values(array_unique($paths));
    if (!$paths) throw new ApiError('בחרו קובץ אחד לפחות (📁) — העוזר עובד על הקבצים שבהקשר');
    if (count($paths) > 40) throw new ApiError('יותר מדי קבצים בהקשר (40 לכל היותר) — צמצם את ההקשר');
    $files = [];
    $total = 0;
    foreach ($paths as $p) {
        [$path, $kind, $s, $rev] = ai_file($p);
        $files[$path] = ['kind' => $kind, 'content' => $s, 'rev' => $rev];
        $total += strlen($s);
    }
    if ($total > ($max = ai_context_max())) {
        throw new ApiError('ההקשר גדול מדי (' . round($total / 1024) . 'KB; התקרה ' . round($max / 1024) . 'KB) — צמצם את ההקשר: פחות קבצים, או טווח שורות', 413, ['size' => $total, 'max' => $max]);
    }
    $msg = "קבצי הפרויקט שבהקשר:\n";
    foreach ($files as $path => $f) $msg .= "\n===== $path =====\n" . $f['content'] . (str_ends_with($f['content'], "\n") ? '' : "\n") . "===== סוף $path =====\n";
    if ($range) {
        $from = max(1, (int)($range['from'] ?? 1));
        $to = max($from, (int)($range['to'] ?? $from));
        $msg .= "\nהעורך סימן בקובץ {$range['path']} את השורות {$from}–{$to} — עבוד עליהן.\n";
    }
    if ($el) $msg .= "\nהעורך בחר בעורך החזותי את האלמנט " . pf_label((string)($el['element'] ?? ''), 300) . " בקובץ {$el['path']} — עבוד עליו.\n";
    $sys = array_values(array_intersect(array_keys($files), ai_system_files()));
    if ($sys) $msg .= "\nקובצי מערכת (מותר לשנות, ויסומנו \"שונה\"): " . implode(', ', $sys) . "\n";
    return [$files, $msg];
}

/**
 * מחיל את העריכות על התוכן (בזיכרון בלבד). מחזיר [path → [סוג, base, new, rev, create], applied (מספרי עריכות), rejected [i, path, why]].
 * $files — ההקשר; קובץ שאינו בהקשר נקרא מהפרויקט (ואז גם הוא נבדק). $only — מספרי העריכות שהעורך השאיר מסומנות.
 */
function ai_plan(array $edits, array $files, ?array $only = null): array
{
    $out = []; $applied = []; $rejected = [];
    foreach (array_slice($edits, 0, AI_EDITS_MAX) as $i => $e) {
        if ($only !== null && !in_array($i, $only, true)) continue;
        $path = (string)($e['path'] ?? '');
        $no = function (string $why) use (&$rejected, $i, $path) { $rejected[] = ['i' => $i, 'path' => $path, 'why' => $why]; };
        try {
            $path = pf_path($path);
            if (pf_key($path) === 'media' || str_starts_with(pf_key($path), 'media/')) { $no('קבצי מדיה אינם בידי העוזר'); continue; }
            if (array_key_exists('create', $e) && is_string($e['create'])) {
                if (isset($out[$path]) || isset($files[$path]) || pf_by_path($path) || $path === pf_words_path()) { $no('הקובץ כבר קיים — יצירה אינה דורסת קובץ'); continue; }
                pf_free($path);
                if (strlen($e['create']) > pf_limit('text')) { $no('הקובץ גדול מהמגבלה'); continue; }
                $out[$path] = ['kind' => 'create', 'base' => '', 'new' => $e['create'], 'rev' => 0];
                $applied[] = $i;
                continue;
            }
            if (!is_string($e['old'] ?? null) || !is_string($e['new'] ?? null)) { $no('חסר old / new'); continue; }
            if (!isset($out[$path])) {
                $f = $files[$path] ?? null;
                if (!$f) { [, $kind, $s, $rev] = ai_file($path); $f = ['kind' => $kind, 'content' => $s, 'rev' => $rev]; }
                $out[$path] = ['kind' => $f['kind'], 'base' => $f['content'], 'new' => $f['content'], 'rev' => $f['rev']];
            }
            if ($out[$path]['kind'] === 'create') { $no('אי אפשר לערוך קובץ שנוצר באותה הצעה — התוכן המלא ב-create'); continue; }
            if ($e['old'] === '') { $no('old ריק'); continue; }
            $n = substr_count($out[$path]['new'], $e['old']);
            if ($n !== 1) { $no($n === 0 ? 'הקטע (old) לא נמצא בקובץ כמו שהוא' : "הקטע (old) נמצא $n פעמים — לא ברור איזה"); continue; }
            $pos = strpos($out[$path]['new'], $e['old']);
            $out[$path]['new'] = substr_replace($out[$path]['new'], $e['new'], $pos, strlen($e['old']));
            $applied[] = $i;
        } catch (ApiError $x) {
            $no($x->getMessage());
        }
    }
    foreach ($out as $p => $f) if ($f['kind'] !== 'create' && $f['new'] === $f['base']) unset($out[$p]);
    return [$out, $applied, $rejected];
}

/** קריאה לבינה. בבדיקות — $GLOBALS['kol_ai_fake'] (fn(array $body) → תשובה כמו של ה-API), בלי רשת. */
function ai_send(array $body): array
{
    if (isset($GLOBALS['kol_ai_fake']) && is_callable($GLOBALS['kol_ai_fake'])) return ($GLOBALS['kol_ai_fake'])($body);
    $key = secret_get('anthropic_api_key') ?? throw new ApiError('עוד לא הוגדר מפתח לבינה — בעל המערכת מגדיר בהגדרות', 503);
    return ai_call($body, $key);
}

/**
 * ✨ בקשה → הצעה: diff לכל קובץ, ובמילים — "מה ישתנה" של N1. לא נכתב דבר בקבצים. $b: prompt, paths[], range?, element?.
 * ההצעה נשמרת בשרת (ai_prop_<request_id>) — "החל" מחיל בדיוק אותה, ולא מה שהדפדפן שולח.
 */
function ai_suggest_files(array $user, array $b): array
{
    pf_seed($user);
    $prompt = trim(mb_substr((string)($b['prompt'] ?? ''), 0, 4000));
    if (mb_strlen($prompt) < 3) throw new ApiError('כתבו מה לשנות');
    if (!isset($GLOBALS['kol_ai_fake']) && (secret_get('anthropic_api_key') === null)) throw new ApiError('עוד לא הוגדר מפתח לבינה — בעל המערכת מגדיר בהגדרות', 503);
    $q = ai_quota($user);
    $used = ai_used($user['sub']);
    if ($q !== null && $used >= $q) throw new ApiError('המכסה החודשית שלך לבינה נגמרה. בעל המערכת יכול להגדיל אותה', 429);
    [$files, $ctx] = ai_context($b);
    $model = ai_model();

    $rid = ai_uuid();
    db()->prepare("INSERT INTO ai_usage (request_id, sub, name, at, model, input_tokens, output_tokens, status, prompt) VALUES (?, ?, ?, ?, ?, 0, 0, 'pending', ?)")
        ->execute([$rid, $user['sub'], $user['name'], now_iso(), $model, mb_substr($prompt, 0, 300)]);
    $body = [
        'model' => $model,
        'max_tokens' => AI_MAX_TOKENS,
        'system' => [['type' => 'text', 'text' => AI_SYSTEM, 'cache_control' => ['type' => 'ephemeral']]],
        'fallbacks' => 'default',
        'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => ai_schema()]],
        'messages' => [['role' => 'user', 'content' => $ctx . "\nהבקשה של העורך:\n" . $prompt]],
    ];
    try {
        $res = ai_send($body);
    } catch (ApiError $e) {
        db()->prepare("UPDATE ai_usage SET status = 'error', error = ? WHERE request_id = ?")->execute([$e->getMessage(), $rid]);
        throw $e;
    }
    $u = $res['usage'] ?? [];
    $in = (int)($u['input_tokens'] ?? 0) + (int)($u['cache_creation_input_tokens'] ?? 0) + (int)($u['cache_read_input_tokens'] ?? 0);
    $out = (int)($u['output_tokens'] ?? 0);
    $stop = (string)($res['stop_reason'] ?? '');
    db()->prepare('UPDATE ai_usage SET status = ?, input_tokens = ?, output_tokens = ?, model = ? WHERE request_id = ?')
        ->execute([$stop === 'refusal' ? 'refused' : 'ok', $in, $out, (string)($res['model'] ?? $model), $rid]);
    if ($stop === 'refusal') throw new ApiError('שירות הבינה סירב לבקשה הזו. אפשר לערוך ידנית', 422);
    if ($stop === 'max_tokens') throw new ApiError('התשובה נקטעה — הבקשה גדולה מדי. צמצם את ההקשר (קובץ אחד, או טווח שורות)', 422);
    $text = '';
    foreach ((array)($res['content'] ?? []) as $blk) if (($blk['type'] ?? '') === 'text') { $text = (string)$blk['text']; break; }
    $parsed = json_decode($text, true);
    if (!is_array($parsed) || !is_array($parsed['edits'] ?? null)) throw new ApiError('התשובה מהבינה לא בצורה הצפויה. נסו שוב', 502);
    $edits = array_values(array_filter(array_slice($parsed['edits'], 0, AI_EDITS_MAX), 'is_array'));

    [$plan, $applied, $rejected] = ai_plan($edits, $files);
    $sys = ai_system_files();
    $view = [];
    foreach ($plan as $path => $f) {
        $v = ['path' => $path, 'kind' => $f['kind'], 'old' => $f['base'], 'new' => $f['new'], 'system' => in_array($path, $sys, true)];
        if ($f['kind'] === 'words') {
            // N1: "מה ישתנה" מהמנוע עצמו — בלי לכתוב דבר. שגיאה בקובץ = העריכות על המילים נדחות, עם הסיבה
            try {
                $w = pf_words_write($user, ['id' => PF_WORDS_ID, 'content' => $f['new'], 'rev' => $f['rev']]);
                $v['plan'] = $w['plan'];
            } catch (ApiError $x) {
                foreach ($edits as $i => $e) if (($e['path'] ?? '') === $path && in_array($i, $applied, true)) $rejected[] = ['i' => $i, 'path' => $path, 'why' => 'המילים: ' . $x->getMessage()];
                $applied = array_values(array_filter($applied, fn($i) => ($edits[$i]['path'] ?? '') !== $path));
                unset($plan[$path]);
                continue;
            }
        }
        $view[] = $v;
    }
    ai_prop_clean();
    setting_set('ai_prop_' . $rid, (string)json_encode(['sub' => $user['sub'], 'at' => time(), 'prompt' => $prompt, 'edits' => $edits,
        'revs' => array_map(fn($f) => $f['rev'], $plan), 'tokens' => array_filter(array_map(fn($v) => $v['plan']['token'] ?? null, array_column($view, null, 'path')))],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    audit_as($user, 'ai', pf_label($prompt, 120) . ' · ' . count($applied) . ' עריכות · ' . count($rejected) . ' נדחו');
    return [
        'request_id' => $rid, 'summary' => mb_substr((string)($parsed['summary'] ?? ''), 0, 600),
        'edits' => array_map(fn($i, $e) => ['i' => $i, 'path' => (string)($e['path'] ?? ''), 'create' => array_key_exists('create', $e), 'ok' => in_array($i, $applied, true)], array_keys($edits), $edits),
        'files' => $view, 'rejected' => $rejected,
        'usage' => ['tokens' => $in + $out, 'used' => $used + $in + $out, 'quota' => $q],
    ];
}

function ai_prop_clean(): void
{
    $st = db()->query("SELECT k, v FROM settings WHERE k LIKE 'ai!_prop!_%' ESCAPE '!'");
    foreach ($st->fetchAll() as $r) {
        $p = json_decode((string)$r['v'], true);
        if (!is_array($p) || ($p['at'] ?? 0) < time() - AI_PROP_TTL) db()->prepare('DELETE FROM settings WHERE k = ?')->execute([$r['k']]);
    }
}

/**
 * "החל": request_id (+ only — מספרי העריכות שנשארו מסומנות). כל קובץ — גרסה אחת בהיסטוריה, "✨ <הבקשה>"; קובץ שהשתנה מאז
 * ההצעה — 409, ושום דבר לא נכתב (לעולם לא דריסה שקטה). המילים — words_apply של N1 (עסקה, נקודת שחזור).
 */
function ai_apply_files(array $user, array $b): array
{
    pf_guard($user);
    $rid = (string)($b['request_id'] ?? '');
    if (!preg_match('/^[0-9a-f-]{36}$/', $rid)) throw new ApiError('הצעה לא תקינה');
    $prop = json_decode((string)setting('ai_prop_' . $rid, ''), true);
    if (!is_array($prop) || $prop['sub'] !== $user['sub']) throw new ApiError('ההצעה לא נמצאה (פג תוקפה, או שכבר הוחלה) — בקשו שוב', 404);
    $only = isset($b['only']) && is_array($b['only']) ? array_values(array_map('intval', $b['only'])) : null;
    [$plan, $applied, $rejected] = ai_plan($prop['edits'], [], $only);
    if (!$plan) throw new ApiError('אין עריכה שאפשר להחיל' . ($rejected ? ': ' . $rejected[0]['why'] : ''), 409, ['rejected' => $rejected]);
    // כל קובץ — מול הגרסה שהוצגה. קובץ שהשתנה מאז — הכול נעצר
    foreach ($plan as $path => $f) {
        $was = $prop['revs'][$path] ?? null;
        if ($f['kind'] === 'create' ? $was !== 0 : $was !== $f['rev']) {
            throw new ApiError("\"$path\" השתנה מאז ההצעה — שום דבר לא נכתב. בקשו הצעה חדשה", 409, ['path' => $path]);
        }
    }
    $note = '✨ ' . pf_label($prop['prompt'], 120);
    $done = [];
    $words = array_filter($plan, fn($f) => $f['kind'] === 'words');
    $rest = array_diff_key($plan, $words);
    foreach ($words as $path => $f) {
        $token = $prop['tokens'][$path] ?? '';
        $r = pf_words_write($user, ['id' => PF_WORDS_ID, 'content' => $f['new'], 'rev' => $f['rev'], 'apply' => true, 'token' => $token]);
        $done[] = ['path' => $path, 'kind' => 'words', 'summary' => $r['plan']['summary'] ?? ($r['summary'] ?? '')];
    }
    pf_tx(function () use ($rest, $user, $note, &$done) {
        foreach ($rest as $path => $f) {
            if ($f['kind'] === 'create') {
                pf_free($path);
                if (($n = pf_count()) >= ($max = pf_limit('files'))) pf_over('יותר מדי קבצים בפרויקט', $n + 1, $max);
                $r = pf_insert($path, 'file', $f['new'], $user, null, $note);
            } else {
                $row = pf_by_path($path) ?? throw new ApiError("\"$path\" לא נמצא", 404);
                if ((int)$row['rev'] !== $f['rev']) throw new ApiError("\"$path\" השתנה מאז ההצעה — שום דבר לא נכתב", 409, ['path' => $path]);
                $r = pf_put($row, $f['new'], $user, $note);
            }
            $done[] = ['path' => $path, 'kind' => $f['kind'], 'file' => pf_public($r)];
        }
    });
    db()->prepare('DELETE FROM settings WHERE k = ?')->execute(['ai_prop_' . $rid]);
    $sys = array_values(array_intersect(array_keys($plan), ai_system_files()));
    audit_as($user, 'ai_apply', pf_label($prop['prompt'], 80) . ' · ' . count($applied) . ' עריכות ב-' . count($done) . ' קבצים · ' . count($rejected) . ' נדחו');
    return ['applied' => $done, 'rejected' => $rejected, 'system' => $sys, 'kol' => isset($plan['kol.json']) ? pf_kol() : null];
}

function ai_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

// ── ניהול (בעלים) ─────────────────────────────────────────

function ai_admin(): array
{
    $st = db()->prepare("SELECT sub, name, COUNT(*) AS calls, SUM(input_tokens + output_tokens) AS tokens FROM ai_usage WHERE at >= ? AND status <> 'error' GROUP BY sub ORDER BY tokens DESC");
    $st->execute([ai_month_start()]);
    return ['secret' => secrets_status(), 'quota_default' => (int)(setting('ai_quota_default') ?? AI_QUOTA_DEFAULT), 'month' => $st->fetchAll(), 'model' => ai_model(), 'context_max' => ai_context_max()];
}

function ai_quota_set(array $owner, array $b): array
{
    $n = (int)($b['tokens'] ?? -1);
    if ($n < 0 || $n > 100_000_000) throw new ApiError('מכסה לא תקינה');
    if (isset($b['member'])) {
        $m = member_get((int)$b['member']);
        db()->prepare('UPDATE members SET ai_quota = ? WHERE id = ?')->execute([($b['inherit'] ?? false) ? null : $n, $m['id']]);
        audit_as($owner, 'ai_quota', ($m['name'] ?: $m['email']) . ': ' . (($b['inherit'] ?? false) ? 'ברירת מחדל' : $n));
    } else {
        setting_set('ai_quota_default', (string)$n);
        audit_as($owner, 'ai_quota', 'ברירת מחדל: ' . $n);
    }
    return ai_admin();
}
