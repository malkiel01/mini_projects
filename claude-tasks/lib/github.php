<?php
/**
 * לקוח GitHub.
 *
 * כל פנייה כאן נעשית עם הטוקן של משתמש מסוים, והוא מועבר במפורש כארגומנט
 * ולא נשלף מהסשן. זו הסיבה: ברגע שכמה אנשים עובדים על אותו פרויקט, אסור
 * שיהיה בקוד מסלול שבו פעולה של משתמש א׳ יוצאת עם הטוקן של ב׳. מי שקורא
 * לפונקציה חייב להחזיק את הטוקן ביד, ולכן חייב לדעת של מי הוא.
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

const GH_API = 'https://api.github.com';
const GH_UA  = 'claude-tasks-board';

/**
 * פנייה גולמית. מחזירה מבנה ולא זורקת על סטטוס שגיאה — יש קוראים
 * שמבדילים בין 404 ל-403, ואסור שהחריגה תמחק את ההבדל.
 */
function ghRequest(string $token, string $method, string $path,
                   ?array $body = null, ?callable $transport = null): array {
    $url  = str_starts_with($path, 'http') ? $path : GH_API . $path;
    $json = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null;

    if ($transport) return $transport($method, $url, $json, $token);
    if (!function_exists('curl_init')) throw new AppError('cURL אינו זמין בשרת');

    $ch = curl_init($url);
    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => array_filter([
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: ' . GH_UA,
            'Authorization: Bearer ' . $token,
            $json !== null ? 'Content-Type: application/json' : null,
        ]),
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            return strlen($line);
        },
    ]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $json);

    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new AppError('הפנייה לגיטהאב נכשלה: ' . $err);

    $data = json_decode((string) $raw, true);
    return ['status' => $code, 'data' => is_array($data) ? $data : [], 'headers' => $headers,
            'raw' => (string) $raw];
}

/**
 * מתרגם סטטוס שגיאה להודעה שאפשר להציג למשתמש.
 *
 * בלי טיפוס החזרה never בכוונה: הוא נוסף ב-PHP 8.1, ואם השרת ירוץ על
 * גרסה ישנה יותר זו שגיאת פרסור שמפילה את כל הקובץ ולא שגיאה נקודתית.
 */
function ghFail(array $res, string $what) {
    $msg = (string) ($res['data']['message'] ?? '');
    throw new AppError(match ($res['status']) {
        401 => 'הטוקן של גיטהאב נדחה — ייתכן שפג או בוטל',
        403 => str_contains($msg, 'rate limit')
                 ? 'חרגת ממכסת הפניות של גיטהאב — נסה שוב בעוד כמה דקות'
                 : "אין הרשאה ל$what — בדוק את ההיקפים (scopes) של הטוקן",
        404 => "$what לא נמצא, או שאין לטוקן גישה אליו",
        422 => "גיטהאב דחה את הבקשה: $msg",
        default => "גיטהאב החזיר שגיאה ({$res['status']}) $msg",
    });
}

function ghOk(array $res, string $what): array {
    if ($res['status'] < 200 || $res['status'] >= 300) ghFail($res, $what);
    return $res['data'];
}

/* ── זהות ─────────────────────────────────────────────────────── */

/**
 * מאמת טוקן ומחזיר את בעליו ואת ההיקפים שלו.
 *
 * ההיקפים מגיעים בכותרת ולא בגוף, ורק כאן — לכן זו הנקודה היחידה שבה
 * אפשר לדעת מה הטוקן מסוגל לעשות לפני שמנסים.
 */
function ghWhoAmI(string $token, ?callable $transport = null): array {
    $res  = ghRequest($token, 'GET', '/user', null, $transport);
    $user = ghOk($res, 'פרטי המשתמש');

    $scopes = array_values(array_filter(array_map(
        'trim', explode(',', (string) ($res['headers']['x-oauth-scopes'] ?? ''))
    )));

    return [
        'login'  => (string) ($user['login'] ?? ''),
        'name'   => (string) ($user['name'] ?? ''),
        'avatar' => (string) ($user['avatar_url'] ?? ''),
        // טוקן עדין (fine-grained) אינו מדווח scopes כלל, ואז הרשימה
        // ריקה — זה תקין, וההרשאות נבדקות בפועל מול הריפו.
        'scopes' => $scopes,
    ];
}

/* ── ריפוזיטוריז ──────────────────────────────────────────────── */

function ghRepos(string $token, int $page = 1, int $perPage = 30, ?callable $transport = null): array {
    $q   = http_build_query([
        'affiliation' => 'owner,collaborator,organization_member',
        'sort'        => 'pushed',
        'per_page'    => max(1, min(100, $perPage)),
        'page'        => max(1, $page),
    ]);
    $rows = ghOk(ghRequest($token, 'GET', "/user/repos?$q", null, $transport), 'רשימת הריפוזיטוריז');

    return array_map(fn($r) => [
        'full_name'      => $r['full_name'] ?? '',
        'owner'          => $r['owner']['login'] ?? '',
        'name'           => $r['name'] ?? '',
        'private'        => (bool) ($r['private'] ?? false),
        'description'    => (string) ($r['description'] ?? ''),
        'default_branch' => (string) ($r['default_branch'] ?? 'main'),
        'pushed_at'      => (string) ($r['pushed_at'] ?? ''),
        'html_url'       => (string) ($r['html_url'] ?? ''),
        // ‏push הוא מה שקובע אם אפשר יהיה לכתוב, ולא בעלות על הריפו.
        'can_push'       => (bool) ($r['permissions']['push'] ?? false),
        'can_admin'      => (bool) ($r['permissions']['admin'] ?? false),
    ], $rows);
}

function ghCreateRepo(string $token, string $name, bool $private, string $description = '',
                      bool $autoInit = true, ?callable $transport = null): array {
    if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $name)) {
        throw new InvalidArgumentException('שם ריפו: אותיות לועזיות, ספרות, נקודה, מקף או קו תחתון');
    }
    $repo = ghOk(ghRequest($token, 'POST', '/user/repos', [
        'name'        => $name,
        'private'     => $private,
        'description' => mb_substr($description, 0, 300),
        // בלי auto_init אין ענף ואין עץ, וכל פעולה ראשונה נכשלת ב-409.
        'auto_init'   => $autoInit,
    ], $transport), 'יצירת ריפו');

    return ['full_name' => $repo['full_name'] ?? '', 'html_url' => $repo['html_url'] ?? '',
            'default_branch' => $repo['default_branch'] ?? 'main'];
}

function ghRepo(string $token, string $owner, string $repo, ?callable $transport = null): array {
    return ghOk(ghRequest($token, 'GET', "/repos/$owner/$repo", null, $transport), "הריפו $owner/$repo");
}

/* ── קבצים ────────────────────────────────────────────────────── */

/** תוכן נתיב: רשימת קבצים בתיקייה, או קובץ יחיד עם התוכן שלו. */
function ghContents(string $token, string $owner, string $repo, string $path = '',
                    string $ref = '', ?callable $transport = null): array {
    $url = "/repos/$owner/$repo/contents/" . implode('/', array_map('rawurlencode', array_filter(explode('/', $path))));
    if ($ref !== '') $url .= '?ref=' . rawurlencode($ref);

    $data = ghOk(ghRequest($token, 'GET', $url, null, $transport), "הנתיב '$path'");

    // תיקייה מוחזרת כמערך של רשומות; קובץ — כרשומה אחת.
    if (isset($data['type']) && $data['type'] === 'file') {
        return ['kind' => 'file', 'path' => $data['path'], 'sha' => $data['sha'],
                'size' => (int) ($data['size'] ?? 0),
                'content' => base64_decode(str_replace("\n", '', (string) ($data['content'] ?? '')), true) ?: '',
                'encoding' => $data['encoding'] ?? ''];
    }

    $items = array_map(fn($e) => [
        'name' => $e['name'] ?? '', 'path' => $e['path'] ?? '',
        'type' => $e['type'] ?? '', 'size' => (int) ($e['size'] ?? 0),
    ], $data);
    usort($items, fn($a, $b) => [$a['type'] === 'file', $a['name']] <=> [$b['type'] === 'file', $b['name']]);

    return ['kind' => 'dir', 'items' => $items];
}

/**
 * כותב קובץ. ‏$sha הוא ה-sha הקיים בעדכון — בלעדיו גיטהאב מסרב, וזה
 * בדיוק מה שמונע דריסה של שינוי שנכנס בינתיים.
 */
function ghPutFile(string $token, string $owner, string $repo, string $path, string $content,
                   string $message, string $branch = '', string $sha = '',
                   ?callable $transport = null): array {
    $body = ['message' => mb_substr($message, 0, 500), 'content' => base64_encode($content)];
    if ($branch !== '') $body['branch'] = $branch;
    if ($sha !== '')    $body['sha']    = $sha;

    $url  = "/repos/$owner/$repo/contents/" . implode('/', array_map('rawurlencode', array_filter(explode('/', $path))));
    $data = ghOk(ghRequest($token, 'PUT', $url, $body, $transport), "כתיבה ל'$path'");

    return ['sha' => $data['content']['sha'] ?? '', 'commit' => $data['commit']['sha'] ?? '',
            'html_url' => $data['content']['html_url'] ?? ''];
}

/* ── Actions ──────────────────────────────────────────────────── */

function ghDispatchWorkflow(string $token, string $owner, string $repo, string $workflow,
                            string $ref, array $inputs = [], ?callable $transport = null): void {
    $res = ghRequest($token, 'POST',
        "/repos/$owner/$repo/actions/workflows/" . rawurlencode($workflow) . '/dispatches',
        ['ref' => $ref, 'inputs' => $inputs], $transport);

    if ($res['status'] !== 204) ghFail($res, "הפעלת ה-workflow '$workflow'");
}

/**
 * שומר סוד בריפו. הערך מוצפן במפתח הציבורי של הריפו לפני השליחה, כך
 * שגם גיטהאב מקבל אותו כבר סגור.
 */
function ghSetSecret(string $token, string $owner, string $repo, string $name, string $value,
                     ?callable $transport = null): void {
    if (!function_exists('sodium_crypto_box_seal')) {
        throw new AppError('ext-sodium חסר בשרת — אי אפשר להצפין סוד עבור גיטהאב');
    }
    $key = ghOk(ghRequest($token, 'GET', "/repos/$owner/$repo/actions/secrets/public-key", null, $transport),
                'מפתח הסודות של הריפו');

    $sealed = sodium_crypto_box_seal($value, base64_decode((string) $key['key'], true) ?: '');
    $res = ghRequest($token, 'PUT', "/repos/$owner/$repo/actions/secrets/" . rawurlencode($name),
        ['encrypted_value' => base64_encode($sealed), 'key_id' => (string) $key['key_id']], $transport);

    if ($res['status'] !== 201 && $res['status'] !== 204) ghFail($res, "שמירת הסוד '$name'");
}
