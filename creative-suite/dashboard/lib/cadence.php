<?php
/**
 * קצב ההערות של הצ'אטים (creative-suite/docs/finish-line.md, "הקצב"). רק פונקציות, בלי פלט.
 *
 * נשמר ב-data/cadence.json — תיקיית נתונים בשרת, עם --exclude ב-deploy.yml וחסומה ב-.htaccess.
 * המבנה: {"default":5,"paused":false,"chats":{"<role>":{"minutes":N|null,"paused":bool}},"updated_at":ts,"updated_by":"…"}
 */
declare(strict_types=1);

const CADENCE_FILE = __DIR__ . '/../data/cadence.json';
const CADENCE_DEFAULT = 5;
const CADENCE_MIN = 1;
const CADENCE_MAX = 1440;

function cadence_load(): array
{
    $raw = @file_get_contents(CADENCE_FILE);
    $d = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($d)) $d = [];
    return [
        'default'    => cadence_clamp($d['default'] ?? CADENCE_DEFAULT) ?? CADENCE_DEFAULT,
        'paused'     => !empty($d['paused']),
        'chats'      => is_array($d['chats'] ?? null) ? $d['chats'] : [],
        'updated_at' => (int)($d['updated_at'] ?? 0),
        'updated_by' => (string)($d['updated_by'] ?? ''),
    ];
}

/** מספר דקות תקין, או null (ריק = "לפי ברירת המחדל"). */
function cadence_clamp($v): ?int
{
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return max(CADENCE_MIN, min(CADENCE_MAX, (int)$v));
}

/** הקצב של צ'אט אחד — מה ש-cadence.php מחזיר. */
function cadence_for(array $c, string $role): array
{
    $mine = $c['chats'][$role] ?? [];
    $min = cadence_clamp($mine['minutes'] ?? null) ?? $c['default'];
    return ['minutes' => $min, 'paused' => $c['paused'] || !empty($mine['paused'])];
}

/** שמירה אטומית: קובץ זמני ואז rename, כדי שקורא בו-זמני לא יראה חצי קובץ. */
function cadence_save(array $c, string $by): void
{
    $dir = dirname(CADENCE_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('אי אפשר ליצור את תיקיית הנתונים');
    $c['updated_at'] = time();
    $c['updated_by'] = $by;
    $tmp = CADENCE_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false
        || !rename($tmp, CADENCE_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('השמירה נכשלה — אין הרשאת כתיבה ל-data/');
    }
}

/** הצ'אטים בצוות — מ-creative-suite/team/roster.json. */
function cadence_roster(): array
{
    $r = json_decode((string)@file_get_contents(__DIR__ . '/../../team/roster.json'), true);
    $out = [];
    foreach ($r['members'] ?? [] as $m) {
        if (preg_match('/^[a-z0-9-]{1,40}$/', (string)($m['role'] ?? ''))) $out[$m['role']] = (string)($m['name'] ?? $m['role']);
    }
    return $out;
}
