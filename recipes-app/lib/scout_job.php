<?php
/**
 * ייבוא ברקע מהסורק — רץ בשרת, גם כשהדפדפן סגור, עד שהרשימה נגמרת או
 * שעוצרים אותו.
 *
 * אין בשרת המשותף (cPanel) תהליך שרץ לנצח, ולכן העובד הוא שרשרת:
 * scout-worker.php מקבל בקשה, עונה מיד, וממשיך לעבוד אחרי שהחיבור נסגר
 * (ignore_user_abort). הוא מייבא פריט, ממתין את המרווח, מייבא את הבא — עד
 * WORKER_BUDGET שניות — ואז מעיר עותק חדש של עצמו בבקשת HTTP ויוצא. כך אף
 * תהליך לא חי יותר מכמה דקות (מארחים הורגים תהליכים ארוכים), והשרשרת
 * ממשיכה כל עוד המצב "running".
 *
 * מה מונע שני עובדים במקביל: נעילת קובץ (flock) לכל חיי התהליך. עובד שלא
 * קיבל אותה — יוצא מיד.
 *
 * מה מחזיר שרשרת שנקטעה (השרת הרג תהליך, בקשה אבדה): שומר-סף. כל קריאה
 * ל-api.php בודקת אם העובד שתק יותר מ-SCOUT_JOB_STALE שניות, ואם כן מעירה
 * אותו. ול-cron של cPanel, אם הוגדר, אותה כתובת — הדרך האמינה ביותר.
 *
 * חסימה של האתר (429 מ-importFetch): לא תקלה בפריט. העובד ממתין עד סוף
 * המנוחה שהאתר קיבל, מגדיל את המרווח פי 1.5 (עד 2 דקות), וממשיך לבד.
 */

declare(strict_types=1);

require_once __DIR__ . '/scout.php';

const SCOUT_JOB_MIN_GAP = 10;
const SCOUT_JOB_MAX_GAP = 180;
const SCOUT_JOB_STALE   = 300;   // עובד ששתק יותר מזה — כנראה מת; מעירים
const SCOUT_WORKER_BUDGET = 200; // שניות לכל תהליך, ואז שרשור לתהליך חדש
const SCOUT_JOB_RECENT  = 30;

function scoutJobRow(): array {
    $pdo = db();
    $row = $pdo->query('SELECT * FROM scout_job WHERE id = 1')->fetch();
    if (!$row) {
        $pdo->prepare('INSERT OR IGNORE INTO scout_job (id, state, worker_key) VALUES (1, ?, ?)')
            ->execute(['idle', bin2hex(random_bytes(20))]);
        $row = $pdo->query('SELECT * FROM scout_job WHERE id = 1')->fetch();
    }
    return $row;
}

function scoutJobUpdate(array $fields): void {
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
    db()->prepare("UPDATE scout_job SET $sets WHERE id = 1")->execute(array_values($fields));
}

/** המצב, כפי שהדפדפן מציג אותו. */
function scoutJobStatus(array $developer): array {
    requireDeveloper($developer);
    $j = scoutJobRow();
    $left = (int) db()->query("SELECT COUNT(*) FROM scout_items WHERE status = 'wanted'")->fetchColumn();
    $now = time();
    return [
        'state'      => $j['state'],
        'gap_sec'    => (int) $j['gap_sec'],
        'rewrite'    => (bool) $j['rewrite'],
        'started_at' => $j['started_at'],
        'ended_at'   => $j['ended_at'],
        'next_in'    => $j['state'] === 'running' ? max(0, (int) $j['next_at'] - $now) : null,
        'last_tick_ago' => (int) $j['last_tick_at'] ? $now - (int) $j['last_tick_at'] : null,
        'alive'      => $j['state'] === 'running' && (int) $j['last_tick_at'] > $now - SCOUT_JOB_STALE,
        'done_n'     => (int) $j['done_n'],
        'fail_n'     => (int) $j['fail_n'],
        'blocks_n'   => (int) $j['blocks_n'],
        'left'       => $left,
        'message'    => $j['message'],
        'recent'     => $j['recent'] ? (json_decode($j['recent'], true) ?: []) : [],
        // ל-cron של cPanel — רק למפתח
        'cron_url'   => $j['base_url'] ? rtrim($j['base_url'], '/') . '/scout-worker.php?key=' . $j['worker_key'] : null,
    ];
}

/** הכתובת של האפליקציה מתוך הבקשה הנוכחית — כדי שהעובד יוכל להעיר את עצמו. */
function scoutJobBaseUrl(): ?string {
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) return null;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
          || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir;
}

function scoutJobStart(array $developer, int $gap, bool $rewrite): array {
    requireDeveloper($developer);
    $left = (int) db()->query("SELECT COUNT(*) FROM scout_items WHERE status = 'wanted'")->fetchColumn();
    if ($left === 0) throw new AppError('אין פריטים מסומנים לייבוא. להדליק את המתג ליד מה שרוצים.');
    $j = scoutJobRow();
    $fields = [
        'state' => 'running', 'user_id' => (int) $developer['id'],
        'gap_sec' => max(SCOUT_JOB_MIN_GAP, min(SCOUT_JOB_MAX_GAP, $gap)), 'rewrite' => $rewrite ? 1 : 0,
        'next_at' => time(), 'message' => "התחיל: $left פריטים לייבוא", 'ended_at' => null,
    ];
    if ($j['state'] !== 'running') {   // ריצה חדשה — מונים מאפס; ריצה פעילה — רק משנים הגדרות
        $fields += ['started_at' => nowIso(), 'done_n' => 0, 'fail_n' => 0, 'blocks_n' => 0, 'recent' => null, 'last_tick_at' => time()];
    }
    if ($base = scoutJobBaseUrl()) $fields['base_url'] = $base;
    scoutJobUpdate($fields);
    logEvent('info', 'scout-job-start', "ייבוא ברקע: $left פריטים", ['gap' => $fields['gap_sec'], 'rewrite' => $rewrite], $developer);
    scoutJobKick();
    return scoutJobStatus($developer);
}

function scoutJobStop(array $developer): array {
    requireDeveloper($developer);
    $j = scoutJobRow();
    if ($j['state'] === 'running') {
        scoutJobUpdate(['state' => 'stopped', 'ended_at' => nowIso(), 'message' => 'נעצר ידנית']);
        logEvent('info', 'scout-job-stop', 'ייבוא ברקע נעצר', ['done' => (int) $j['done_n']], $developer);
    }
    return scoutJobStatus($developer);
}

/**
 * מעיר את העובד: בקשת HTTP ל-scout-worker.php, בלי לחכות לתשובה. העובד
 * ממשיך גם אחרי שהחיבור נסגר. בלי base_url (בדיקות, CLI) — לא עושה כלום.
 */
function scoutJobKick(): void {
    $j = scoutJobRow();
    if ($j['state'] !== 'running' || !$j['base_url'] || !function_exists('curl_init')) return;
    $url = rtrim($j['base_url'], '/') . '/scout-worker.php?key=' . $j['worker_key'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 1500, CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_NOSIGNAL => true, CURLOPT_NOPROXY => '127.0.0.1,localhost',
        CURLOPT_USERAGENT => 'recipes-app/scout-worker',
    ]);
    curl_exec($ch);   // timeout צפוי — העובד עונה ואז עובד; לא מחכים לו
    curl_close($ch);
}

/** שומר-סף: נקרא מ-api.php. עובד שאמור לרוץ ושתק — מעירים. זול: קריאה של שורה אחת. */
function scoutJobWatchdog(): void {
    try {
        $j = db()->query('SELECT state, last_tick_at FROM scout_job WHERE id = 1')->fetch();
        if (!$j || $j['state'] !== 'running' || (int) $j['last_tick_at'] > time() - SCOUT_JOB_STALE) return;
        scoutJobUpdate(['last_tick_at' => time()]);   // שלא יעירו אותו עשר בקשות במקביל
        scoutJobKick();
    } catch (Throwable $e) { error_log('recipes-app scout watchdog: ' . $e->getMessage()); }
}

/**
 * צעד אחד של העובד: אם הגיע הזמן — מייבא את הפריט הבא. מחזיר:
 *   'idle' — אין ריצה, 'wait' — עוד לא הגיע הזמן, 'done' — הרשימה נגמרה,
 *   'imported' / 'failed' / 'blocked' — מה קרה לפריט.
 */
function scoutJobTick(): string {
    $j = scoutJobRow();
    if ($j['state'] !== 'running') return 'idle';
    $now = time();
    scoutJobUpdate(['last_tick_at' => $now]);
    if ($now < (int) $j['next_at']) return 'wait';

    $st = db()->prepare('SELECT id, username, display_name, role FROM users WHERE id = ?');
    $st->execute([(int) $j['user_id']]);
    $dev = $st->fetch();
    $st->closeCursor();
    if (!$dev || !isDeveloper($dev)) {
        scoutJobUpdate(['state' => 'stopped', 'ended_at' => nowIso(), 'message' => 'נעצר: מי שהפעיל כבר אינו המפתח']);
        return 'idle';
    }
    $item = db()->query("SELECT id, url, title, site FROM scout_items WHERE status = 'wanted' ORDER BY id LIMIT 1")->fetch();
    if (!$item) {
        scoutJobUpdate(['state' => 'done', 'ended_at' => nowIso(),
                        'message' => sprintf('הסתיים: %d יובאו, %d נכשלו', (int) $j['done_n'], (int) $j['fail_n'])]);
        logEvent('info', 'scout-job-done', 'ייבוא ברקע הסתיים', ['done' => (int) $j['done_n'], 'failed' => (int) $j['fail_n']], $dev);
        return 'done';
    }

    $gap = (int) $j['gap_sec'];
    $jitter = fn(int $g) => $g + random_int(0, (int) ceil($g * 0.5));   // לא קצב מכונה קבוע
    $entry = ['id' => (int) $item['id'], 'title' => $item['title'], 'at' => nowIso()];
    try {
        $r = scoutImportOne((int) $item['id'], $dev, (bool) $j['rewrite']);
        $entry = ['title' => $r['title'] ?? $item['title']] + $entry
               + ['ok' => true, 'recipe_id' => $r['recipe_id'], 'rewritten' => $r['rewritten'] ?? false, 'note' => $r['ai_error'] ?? null];
        $fields = ['done_n' => (int) $j['done_n'] + 1, 'next_at' => time() + $jitter($gap),
                   'message' => 'יובא: ' . ($r['title'] ?? $item['title'])];
        $result = 'imported';
    } catch (AppError $e) {
        if ($e->status === 429) {
            // האתר חסם: ממתינים לסוף המנוחה שלו, ומאטים
            $until = importHostBlockedUntil((string) $item['site']) ?? (time() + IMPORT_BLOCK_COOLDOWN);
            $gap = min(SCOUT_JOB_MAX_GAP, (int) ceil($gap * 1.5));
            $entry += ['ok' => false, 'blocked' => true, 'note' => $e->getMessage()];
            $fields = ['blocks_n' => (int) $j['blocks_n'] + 1, 'gap_sec' => $gap, 'next_at' => $until + 60,
                       'message' => $e->getMessage() . " ממשיך לבד אחרי ההמתנה, במרווח של $gap שניות."];
            $result = 'blocked';
        } else {
            $entry += ['ok' => false, 'note' => $e->getMessage()];
            $fields = ['fail_n' => (int) $j['fail_n'] + 1, 'next_at' => time() + $jitter($gap),
                       'message' => 'נכשל: ' . $item['title'] . ' — ' . $e->getMessage()];
            $result = 'failed';
        }
    }
    $recent = $j['recent'] ? (json_decode($j['recent'], true) ?: []) : [];
    array_unshift($recent, $entry);
    $fields['recent'] = json_encode(array_slice($recent, 0, SCOUT_JOB_RECENT), JSON_UNESCAPED_UNICODE);
    $fields['last_tick_at'] = time();
    // ייתכן שבזמן הייבוא עצרו — לא לדרוס את "stopped"
    $state = db()->query('SELECT state FROM scout_job WHERE id = 1')->fetchColumn();
    if ($state !== 'running') unset($fields['next_at'], $fields['message']);
    scoutJobUpdate($fields);
    return $result;
}

/**
 * גוף העובד: צעדים עד שנגמר התקציב, עם שינה עד הצעד הבא, ואז שרשור.
 * $once — צעד אחד בלי שינה ובלי שרשור (בדיקות, ובדיקת cron ידנית).
 */
function scoutWorkerRun(bool $once = false): string {
    $lock = fopen(scoutStateDir() . '/worker.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 'busy';   // עובד אחר חי
    $t0 = time();
    $last = 'idle';
    try {
        do {
            try {
                $last = scoutJobTick();
            } catch (Throwable $e) {
                // תקלה זמנית (מסד נעול, רשת): העובד לא מת. הפריט נשאר "לייבוא", מנסים שוב אחרי מרווח.
                error_log('recipes-app scout worker: ' . $e->getMessage());
                logEvent('warn', 'scout-job-retry', get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200));
                try {
                    $g = (int) scoutJobRow()['gap_sec'];
                    scoutJobUpdate(['next_at' => time() + $g, 'last_tick_at' => time(),
                                    'message' => 'תקלה זמנית — מנסה שוב בעוד ' . $g . ' שניות']);
                } catch (Throwable $e2) {}
                $last = 'retry';
            }
            if ($once || in_array($last, ['idle', 'done'], true)) break;
            $j = scoutJobRow();
            $wait = max(1, (int) $j['next_at'] - time());
            $budget = SCOUT_WORKER_BUDGET - (time() - $t0);
            if ($wait >= $budget) {   // ההמתנה ארוכה מהתקציב — לישון מה שנשאר, ולשרשר
                sleep(max(0, min($wait, $budget - 5)));
                break;
            }
            sleep($wait);
        } while (time() - $t0 < SCOUT_WORKER_BUDGET);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    if (!$once && !in_array($last, ['idle', 'done'], true)) scoutJobKick();   // השרשרת ממשיכה
    return $last;
}
