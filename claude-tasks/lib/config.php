<?php
/**
 * הגדרות הריצה — אסימון העובד ומפתח המודל.
 *
 * יושב ב-data/config.json, מחוץ לגיט, בהרשאות 0600 ומאחורי חסימת
 * ‏.htaccess. מופרד מ-auth.php כי גם הקטלוג האוטומטי צריך אותו, והוא
 * לא אמור לגרור איתו סשנים והרשאות.
 */

declare(strict_types=1);

require_once __DIR__ . '/classify.php';

if (!defined('CONFIG_FILE')) define('CONFIG_FILE', __DIR__ . '/../data/config.json');

function config(bool $reload = false): array {
    static $cache = null;
    if (!$reload && is_array($cache)) return $cache;
    if ($reload) $cache = null;

    if (is_file(CONFIG_FILE)) {
        $c = json_decode((string) file_get_contents(CONFIG_FILE), true);
        if (is_array($c) && !empty($c['worker_token'])) return $cache = $c;
    }
    // נוצר בפנייה הראשונה, כך שאין ברירת מחדל ידועה מראש.
    return $cache = configWrite(['worker_token' => bin2hex(random_bytes(24)), 'created_at' => date('c')]);
}

function configWrite(array $c): array {
    ensureDataDir(dirname(CONFIG_FILE));
    file_put_contents(CONFIG_FILE, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod(CONFIG_FILE, 0600);
    return $c;
}

/** מעדכן שדות בודדים. ערך null מוחק את השדה. */
function configSet(array $patch): array {
    $c = config();
    foreach ($patch as $k => $v) {
        if ($v === null) unset($c[$k]); else $c[$k] = $v;
    }
    configWrite($c);
    return config(true);   // מרעננים את המטמון, אחרת קוראים אחריו יקבלו ערך ישן
}

/**
 * החיבור הכללי של המערכת — משמש כשלמשתמש אין ספק משלו.
 *
 * משתנה סביבה גובר על הקובץ, כדי שאפשר יהיה להחזיק את המפתח מחוץ
 * לדיסק בשרתים שמאפשרים זאת.
 */
function aiConn(): array {
    $c        = config();
    $provider = (string) ($c['ai_provider'] ?? 'anthropic');
    if (!providerExists($provider)) $provider = 'anthropic';

    $env = $provider === 'anthropic' ? getenv('ANTHROPIC_API_KEY') : false;
    // ai_key הוא השם הנוכחי; anthropic_key נקרא כדי לא לאבד הגדרה ישנה.
    $key = is_string($env) && $env !== ''
        ? $env
        : (string) ($c['ai_key'] ?? $c['anthropic_key'] ?? '');

    $model = trim((string) ($c['ai_model'] ?? ''));
    if ($model === '') $model = PROVIDERS[$provider]['default'] ?? '';

    return ['provider' => $provider, 'key' => $key, 'model' => $model,
            'from_env' => is_string($env) && $env !== ''];
}

function aiKey(): string   { return aiConn()['key']; }
function aiModel(): string { return aiConn()['model']; }

/** האם לקטלג אוטומטית בהוספת מטלה. ברירת המחדל: כן. */
function aiAuto(): bool { return (bool) (config()['auto_catalog'] ?? true); }

/**
 * מצב הקטלוג לתצוגה. אף פעם לא מחזיר את המפתח עצמו — רק ארבע ספרות
 * אחרונות, מספיק כדי לזהות איזה מפתח מוגדר.
 */
function aiStatus(): array {
    $conn = aiConn();
    return [
        'provider'     => $conn['provider'],
        'label'        => PROVIDERS[$conn['provider']]['label'] ?? $conn['provider'],
        'model'        => $conn['model'],
        'auto_catalog' => aiAuto(),
        'has_key'      => $conn['key'] !== '',
        'key_tail'     => substr($conn['key'], -4),
        'key_from_env' => $conn['from_env'],
    ];
}
