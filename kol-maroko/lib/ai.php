<?php
/**
 * עוזר הבינה המלאכותית (docs/spec.md, פרק 8) — Claude דרך בקשת HTTP ישירה ל-/v1/messages, כמו
 * recipes-app/lib/ai.php (הכלי נפרס ב-rsync בלי Composer, ולכן בלי ה-SDK).
 *
 * הבינה לא כותבת HTML ולא שומרת כלום. היא מקבלת את העמוד (תוכן הרכיבים, בלי העטיפות המקוריות) ואת הבקשה,
 * ומחזירה רשימת שינויים במבנה קבוע (פלט מובנה לפי סכימה): עדכון / הוספה / מחיקה / הזזה, לפי מזהה רכיב.
 * השרת מחיל אותם על עותק, מנקה (page_normalize) ומחזיר לתצוגה — ורק "החלה" בדפדפן מכניסה לטיוטה.
 * קטעי קוד וקטעים מקוריים — לא נערכים בבינה (רק מחיקה / הזזה, שנראות בתצוגה).
 *
 * מכסה: טוקנים לחודש לכל חבר (הבעלים — בלי מכסה). כל קריאה מקבלת request_id לפני שהיא יוצאת ונרשמת גם בכשל —
 * כך הדיווח לתוסף התשלומים (payments/) יהיה פעם אחת בדיוק (reported_at).
 */

declare(strict_types=1);

require_once __DIR__ . '/draft.php';
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/members.php';

const AI_MODEL = 'claude-opus-5-5';
const AI_MAX_TOKENS = 16000;
const AI_TIMEOUT = 150;
const AI_QUOTA_DEFAULT = 300_000;                       // טוקנים לחודש לחבר, עד שהבעלים קובע אחרת
const AI_ADD_TYPES = ['heading', 'text', 'button', 'divider', 'spacer'];
const AI_TYPE_HE = ['heading' => 'כותרת', 'text' => 'טקסט', 'longtext' => 'טקסט ארוך', 'button' => 'כפתור', 'divider' => 'קו', 'spacer' => 'רווח',
    'image' => 'תמונה', 'gallery' => 'גלריה', 'audio' => 'הקלטה', 'video' => 'וידאו', 'file' => 'קובץ', 'code' => 'קוד', 'raw' => 'קטע מקורי', 'lexicon' => 'המילון'];
const AI_EDIT = [                                        // מה מותר לבינה לשנות בכל סוג
    'heading' => ['text', 'level'], 'text' => ['html'], 'button' => ['label', 'href'], 'image' => ['alt', 'caption'],
    'audio' => ['title'], 'file' => ['label'], 'spacer' => ['height'],
];

function ai_endpoint(): string
{
    return defined('KOL_AI_ENDPOINT') ? KOL_AI_ENDPOINT : 'https://api.anthropic.com/v1/messages';
}

const AI_SYSTEM = <<<'TXT'
אתה עוזר עריכה בסטודיו "קול מרוקו" — עמוד אחד באתר מגרב (לקסיקון של מילים ממרוקו, עם הקלטות של דוברים). העורך מבקש
שינוי בעמוד, ואתה מחזיר רשימת שינויים מדויקת — לא טקסט חופשי.

העמוד: פסים (sections) ← עמודות (columns) ← רכיבים (widgets). לכל רכיב id, type ו-props. סוגים:
- heading: props.text (טקסט רגיל), props.level (1–6).
- text: props.html — HTML פשוט בלבד: p, br, strong, em, u, s, a href="https://…", ul, ol, li, h2–h4, blockquote, span.
- button: props.label, props.href (https בלבד).
- image: props.alt (תיאור לעיוורים), props.caption. התמונה עצמה — לא בידך.
- audio: props.title. ההקלטה עצמה — לא בידך.
- file: props.label.
- spacer: props.height (פיקסלים).
- divider, gallery, video, longtext, code, raw — לא נערכים בתוכן (מותר להזיז או למחוק רק אם ביקשו במפורש).

השינויים (ops):
- update: target = id של רכיב; ממלאים רק את השדות שמשתנים.
- add: type (heading / text / button / divider / spacer) ושדות; after = id של רכיב שאחריו נכנס החדש (או id של פס — בסוף העמודה הראשונה שלו).
- remove: target = id.
- move: target = id; after = id של רכיב שאחריו הוא עובר.

כללים:
- שנה רק מה שהתבקש. אל תשכתב טקסט שלא ביקשו לשנות, ואל תשנה סדר בלי בקשה.
- שמור על הסגנון והשפה של העמוד: עברית, מימין לשמאל. מילים במרוקאית / בערבית / בצרפתית — כפי שהן.
- כשמשנים טקסט ב-html — החזר את ה-html המלא של הרכיב אחרי השינוי.
- אל תמציא עובדות על מילים, מקורות או דוברים. אם הבקשה דורשת מידע שאין בעמוד — כתוב זאת ב-summary, ואל תוסיף.
- אם אי אפשר לבצע (למשל: להחליף הקלטה) — ops ריק, וההסבר ב-summary.
- summary: משפט או שניים בעברית — מה שינית, או למה לא.
TXT;

function ai_schema(): array
{
    $str = ['type' => 'string'];
    return [
        'type' => 'object',
        'properties' => [
            'summary' => $str,
            'ops' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'op' => ['type' => 'string', 'enum' => ['update', 'add', 'remove', 'move']],
                    'target' => $str, 'after' => $str,
                    'type' => ['type' => 'string', 'enum' => AI_ADD_TYPES],
                    'text' => $str, 'level' => ['type' => 'integer'], 'html' => $str, 'label' => $str, 'href' => $str,
                    'alt' => $str, 'caption' => $str, 'title' => $str, 'height' => ['type' => 'integer'],
                ],
                'required' => ['op'],
                'additionalProperties' => false,
            ]],
        ],
        'required' => ['summary', 'ops'],
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
    return ['ready' => secret_get('anthropic_api_key') !== null && function_exists('curl_init'), 'quota' => $q, 'used' => ai_used($user['sub']), 'model' => AI_MODEL];
}

// ── הקריאה ────────────────────────────────────────────────

/** העמוד כפי שהבינה רואה אותו: מזהים, סוגים ותוכן. בלי עטיפות מקוריות, CSS ועיצוב. */
function ai_page_view(array $page, array $only = []): array
{
    $out = [];
    foreach ($page['sections'] as $s) {
        $cols = [];
        foreach ($s['columns'] as $c) {
            $ws = [];
            foreach ($c['widgets'] as $w) {
                if ($only && !in_array($w['id'], $only, true)) continue;
                $p = $w['props'];
                $view = ['id' => $w['id'], 'type' => $w['type']];
                foreach (AI_EDIT[$w['type']] ?? [] as $k) $view[$k] = $p[$k] ?? '';
                if ($w['type'] === 'raw' || $w['type'] === 'longtext') {
                    $view['preview'] = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($p['html'] ?? ($GLOBALS['kol_long_txt'][$p['doc'] ?? ''] ?? ''))))), 0, 300);
                }
                $ws[] = $view;
            }
            if ($ws || !$only) $cols[] = $ws;
        }
        if (array_filter($cols)) $out[] = ['id' => $s['id'], 'columns' => $cols];
    }
    return $out;
}

function ai_request(array $page, string $prompt, array $only): array
{
    $view = json_encode(ai_page_view($page, $only), JSON_UNESCAPED_UNICODE);
    $msg = ($only ? "העורך בחר את הרכיבים האלה — עבוד רק עליהם:\n" : "העמוד:\n") . $view . "\n\nהבקשה של העורך:\n" . $prompt;
    return [
        'model' => AI_MODEL,
        'max_tokens' => AI_MAX_TOKENS,
        // ההוראות קבועות — נשמרות במטמון בין הקריאות
        'system' => [['type' => 'text', 'text' => AI_SYSTEM, 'cache_control' => ['type' => 'ephemeral']]],
        'fallbacks' => 'default',
        'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => ai_schema()]],
        'messages' => [['role' => 'user', 'content' => $msg]],
    ];
}

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

/**
 * בקשה לבינה → הצעה (עמוד מוצע + מה השתנה). לא נשמר כלום בטיוטה. $b: prompt, only (מזהי רכיבים שנבחרו).
 */
function ai_suggest(array $user, array $b): array
{
    $prompt = trim(mb_substr((string)($b['prompt'] ?? ''), 0, 4000));
    if (mb_strlen($prompt) < 3) throw new ApiError('כתבו מה לשנות');
    $key = secret_get('anthropic_api_key') ?? throw new ApiError('עוד לא הוגדר מפתח לבינה — בעל המערכת מגדיר בהגדרות', 503);
    $q = ai_quota($user);
    $used = ai_used($user['sub']);
    if ($q !== null && $used >= $q) throw new ApiError('המכסה החודשית שלך לבינה נגמרה. בעל המערכת יכול להגדיל אותה', 429);

    $page = page_normalize(json_decode((string)draft_row()['json'], true));
    $only = array_values(array_filter((array)($b['only'] ?? []), fn($x) => is_string($x) && preg_match('/^[a-z0-9]{8}$/', $x)));
    $docs = array_filter(array_map(fn($w) => $w['type'] === 'longtext' ? $w['props']['doc'] : '', page_widgets($page)));
    $GLOBALS['kol_long_txt'] = array_map(fn($r) => $r['html'], longtexts_get(array_values($docs)));

    $rid = ai_uuid();
    db()->prepare("INSERT INTO ai_usage (request_id, sub, name, at, model, input_tokens, output_tokens, status, prompt) VALUES (?, ?, ?, ?, ?, 0, 0, 'pending', ?)")
        ->execute([$rid, $user['sub'], $user['name'], now_iso(), AI_MODEL, mb_substr($prompt, 0, 300)]);
    try {
        $res = ai_call(ai_request($page, $prompt, $only), $key);
    } catch (ApiError $e) {
        db()->prepare("UPDATE ai_usage SET status = 'error', error = ? WHERE request_id = ?")->execute([$e->getMessage(), $rid]);
        throw $e;
    }
    $u = $res['usage'] ?? [];
    $in = (int)($u['input_tokens'] ?? 0) + (int)($u['cache_creation_input_tokens'] ?? 0) + (int)($u['cache_read_input_tokens'] ?? 0);
    $out = (int)($u['output_tokens'] ?? 0);
    $stop = (string)($res['stop_reason'] ?? '');
    db()->prepare('UPDATE ai_usage SET status = ?, input_tokens = ?, output_tokens = ?, model = ? WHERE request_id = ?')
        ->execute([$stop === 'refusal' ? 'refused' : 'ok', $in, $out, (string)($res['model'] ?? AI_MODEL), $rid]);

    if ($stop === 'refusal') throw new ApiError('שירות הבינה סירב לבקשה הזו. אפשר לערוך ידנית', 422);
    if ($stop === 'max_tokens') throw new ApiError('התשובה נקטעה — הבקשה גדולה מדי. נסו על חלק מהעמוד (בחרו רכיב)', 422);
    $text = '';
    foreach ((array)($res['content'] ?? []) as $blk) if (($blk['type'] ?? '') === 'text') { $text = (string)$blk['text']; break; }
    $parsed = json_decode($text, true);
    if (!is_array($parsed) || !is_array($parsed['ops'] ?? null)) throw new ApiError('התשובה מהבינה לא בצורה הצפויה. נסו שוב', 502);

    [$proposed, $applied, $skipped] = ai_apply($page, $parsed['ops']);
    audit_as($user, 'ai', mb_substr($prompt, 0, 120) . ' · ' . count($applied) . ' שינויים');
    return [
        'request_id' => $rid, 'summary' => mb_substr((string)($parsed['summary'] ?? ''), 0, 600),
        'applied' => $applied, 'skipped' => $skipped, 'page' => json_decode(page_json($proposed)),
        'usage' => ['tokens' => $in + $out, 'used' => $used + $in + $out, 'quota' => $q],
    ];
}

function ai_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** מחיל את השינויים על עותק. מחזיר [עמוד מנוקה, מה הוחל, מה דולג ולמה]. */
function ai_apply(array $page, array $ops): array
{
    $applied = $skipped = [];
    $find = function (string $id) use (&$page): ?array {
        foreach ($page['sections'] as $si => $s) {
            if ($s['id'] === $id) return ['s' => $si];
            foreach ($s['columns'] as $ci => $c) foreach ($c['widgets'] as $wi => $w) if ($w['id'] === $id) return ['s' => $si, 'c' => $ci, 'w' => $wi];
        }
        return null;
    };
    $insertAfter = function (array $widget, string $after) use (&$page, $find): bool {
        $at = $after !== '' ? $find($after) : null;
        if (!$at) { $last = count($page['sections']) - 1; if ($last < 0) return false; $at = ['s' => $last]; }
        if (!isset($at['w'])) { $page['sections'][$at['s']]['columns'][0]['widgets'][] = $widget; return true; }
        array_splice($page['sections'][$at['s']]['columns'][$at['c']]['widgets'], $at['w'] + 1, 0, [$widget]);
        return true;
    };
    $label = fn($op, $t) => ['op' => $op, 'type' => $t];
    foreach (array_slice($ops, 0, 60) as $o) {
        if (!is_array($o)) continue;
        $op = (string)($o['op'] ?? '');
        $target = (string)($o['target'] ?? '');
        $at = $target !== '' ? $find($target) : null;
        $w = $at && isset($at['w']) ? $page['sections'][$at['s']]['columns'][$at['c']]['widgets'][$at['w']] : null;
        if ($op === 'update') {
            if (!$w) { $skipped[] = ['op' => $op, 'why' => 'רכיב לא נמצא']; continue; }
            $allowed = AI_EDIT[$w['type']] ?? [];
            $changed = false;
            foreach ($allowed as $k) {
                if (!array_key_exists($k, $o)) continue;
                $page['sections'][$at['s']]['columns'][$at['c']]['widgets'][$at['w']]['props'][$k] = $o[$k];
                $changed = true;
            }
            if ($changed) $applied[] = $label('update', $w['type']) + ['id' => $w['id']];
            else $skipped[] = ['op' => $op, 'why' => (AI_TYPE_HE[$w['type']] ?? $w['type']) . ' — העוזר לא משנה את התוכן שלו'];
        } elseif ($op === 'add') {
            $type = (string)($o['type'] ?? '');
            if (!in_array($type, AI_ADD_TYPES, true)) { $skipped[] = ['op' => $op, 'why' => 'הוספת ' . (AI_TYPE_HE[$type] ?? 'רכיב כזה') . ' — רק ידנית']; continue; }
            $props = array_intersect_key($o, array_flip(AI_EDIT[$type] ?? []));
            $nw = ['id' => kid(), 'type' => $type, 'props' => $props, 'style' => []];
            if ($insertAfter($nw, (string)($o['after'] ?? ''))) $applied[] = $label('add', $type) + ['id' => $nw['id']];
            else $skipped[] = ['op' => $op, 'why' => 'אין לאן להוסיף'];
        } elseif ($op === 'remove') {
            if (!$w) { $skipped[] = ['op' => $op, 'why' => 'רכיב לא נמצא']; continue; }
            // המילון — כל העמוד באתר; מחיקה שלו רק ידנית
            if ($w['type'] === 'lexicon') { $skipped[] = ['op' => $op, 'why' => 'המילון — מחיקה רק ידנית']; continue; }
            array_splice($page['sections'][$at['s']]['columns'][$at['c']]['widgets'], $at['w'], 1);
            $applied[] = $label('remove', $w['type']) + ['id' => $w['id']];
        } elseif ($op === 'move') {
            $after = (string)($o['after'] ?? '');
            if (!$w || $after === $target || !$find($after)) { $skipped[] = ['op' => $op, 'why' => 'רכיב או יעד לא נמצאו']; continue; }
            array_splice($page['sections'][$at['s']]['columns'][$at['c']]['widgets'], $at['w'], 1);
            $insertAfter($w, $after);
            $applied[] = $label('move', $w['type']) + ['id' => $w['id']];
        } else {
            $skipped[] = ['op' => $op, 'why' => 'פעולה לא מוכרת'];
        }
    }
    return [page_normalize($page), $applied, $skipped];
}

// ── ניהול (בעלים) ─────────────────────────────────────────

function ai_admin(): array
{
    $st = db()->prepare("SELECT sub, name, COUNT(*) AS calls, SUM(input_tokens + output_tokens) AS tokens FROM ai_usage WHERE at >= ? AND status <> 'error' GROUP BY sub ORDER BY tokens DESC");
    $st->execute([ai_month_start()]);
    return ['secret' => secrets_status(), 'quota_default' => (int)(setting('ai_quota_default') ?? AI_QUOTA_DEFAULT), 'month' => $st->fetchAll(), 'model' => AI_MODEL];
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
