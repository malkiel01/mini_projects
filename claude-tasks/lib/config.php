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
    $dir = dirname(CONFIG_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
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
 * מפתח ה-API של Anthropic. משתנה סביבה גובר על הקובץ, כדי שאפשר יהיה
 * להחזיק אותו מחוץ לדיסק בשרתים שמאפשרים זאת.
 */
function aiKey(): string {
    $env = getenv('ANTHROPIC_API_KEY');
    if (is_string($env) && $env !== '') return $env;
    return (string) (config()['anthropic_key'] ?? '');
}

function aiModel(): string {
    $m = trim((string) (config()['ai_model'] ?? ''));
    return $m !== '' ? $m : ANTHROPIC_MODEL;
}

/** האם לקטלג אוטומטית בהוספת מטלה. ברירת המחדל: כן. */
function aiAuto(): bool { return (bool) (config()['auto_catalog'] ?? true); }

/**
 * מצב הקטלוג לתצוגה. אף פעם לא מחזיר את המפתח עצמו — רק ארבע ספרות
 * אחרונות, מספיק כדי לזהות איזה מפתח מוגדר.
 */
function aiStatus(): array {
    $key = aiKey();
    return [
        'model'        => aiModel(),
        'auto_catalog' => aiAuto(),
        'has_key'      => $key !== '',
        'key_tail'     => $key !== '' ? substr($key, -4) : '',
        'key_from_env' => is_string(getenv('ANTHROPIC_API_KEY')) && getenv('ANTHROPIC_API_KEY') !== '',
    ];
}
