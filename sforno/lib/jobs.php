<?php
/**
 * שירותי רקע (אפיון 01 §8.5, 00 §3.1). אין דמון ואין "רענון עמודים": טבלת jobs + cron.php שהמתזמן של cPanel קורא
 * לו כל 5 דקות. אם המתזמן לא רץ — בדיקת התקינות מתריעה (§18: תזכורות לא יסתמכו על רענון עמודים).
 *
 * שירות חדש: sf_job_register('kind', 'מה הוא עושה', handler, כל כמה זמן או null). ה-handler מקבל payload; חריגה =
 * ניסיון שנכשל. last_error — הודעה טכנית (סוג ומיקום), בלי תוכן.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

const SF_JOB_BACKOFF = [60, 300, 1800];    // דקה, 5, 30
const SF_JOB_STUCK = 600;                  // running מעל 10 דקות — נהרגה באמצע
const SF_CRON_BUDGET = 25.0;

/** $daily — המשימה נכנסת לתור פעם ביום לבד (dedupe_key לפי התאריך, UTC). */
function sf_job_register(string $kind, string $label, callable $handler, bool $daily = false): void
{
    $GLOBALS['sf_job_kinds'][$kind] = ['label' => $label, 'handler' => $handler, 'daily' => $daily];
}

function sf_job_kinds(): array
{
    return $GLOBALS['sf_job_kinds'] ?? [];
}

/** מכניס משימה לתור. dedupe_key כפול — לא נכנסת שנייה (מחזיר null). מחזיר pid. */
function sf_job_add(string $kind, array $payload = [], ?string $dedupe = null, int $delay = 0, int $maxAttempts = 3): ?string
{
    if ($dedupe !== null && sf_val('SELECT 1 FROM jobs WHERE dedupe_key = ?', [$dedupe])) return null;
    $pid = sf_pid();
    try {
        sf_insert('jobs', ['pid' => $pid, 'kind' => $kind, 'payload' => json_encode((object)$payload, JSON_UNESCAPED_UNICODE), 'dedupe_key' => $dedupe,
            'status' => 'pending', 'attempts' => 0, 'max_attempts' => max(1, $maxAttempts), 'run_after' => sf_iso(sf_time() + $delay), 'created_at' => sf_now()]);
    } catch (PDOException $e) {
        // מרוץ: שתי הרצות הכניסו באותו רגע — האינדקס הייחודי עוצר את השנייה
        if ($dedupe !== null && (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate'))) return null;
        throw $e;
    }
    return $pid;
}

/** השירותים היומיים: משימה אחת לכל יום. */
function sf_jobs_schedule(): void
{
    foreach (sf_job_kinds() as $kind => $k) {
        if ($k['daily']) sf_job_add($kind, [], $kind . ':' . gmdate('Y-m-d', sf_time()));
    }
}

/**
 * סבב: מריץ משימות pending שמועדן הגיע, עד $budget שניות. נעילה בקובץ (data/cron.lock) — שתי הרצות לא רצות
 * במקביל; השנייה חוזרת מיד ב-['skipped' => 'locked'] בלי לעבוד.
 */
function sf_jobs_run(float $budget = SF_CRON_BUDGET): array
{
    $dir = sf_data_dir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $lock = @fopen($dir . '/cron.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        return ['skipped' => 'locked'];
    }
    $t0 = microtime(true);
    try {
        sf_meta_set('cron_last_run_at', sf_now());
        // משימה שנהרגה באמצע חוזרת לתור (הניסיון כבר נספר), או נכשלת סופית
        sf_exec("UPDATE jobs SET status = CASE WHEN attempts >= max_attempts THEN 'failed' ELSE 'pending' END,
                last_error = 'הופסקה באמצע (מגבלת זמן)', finished_at = CASE WHEN attempts >= max_attempts THEN ? ELSE NULL END
            WHERE status = 'running' AND started_at < ?", [sf_now(), sf_iso(sf_time() - SF_JOB_STUCK)]);
        sf_jobs_schedule();
        $ran = $failed = 0;
        while (microtime(true) - $t0 < $budget) {
            $job = sf_row("SELECT * FROM jobs WHERE status = 'pending' AND run_after <= ? ORDER BY run_after, id LIMIT 1", [sf_now()]);
            if (!$job) break;
            if (sf_job_exec($job)) $ran++; else $failed++;
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        sf_meta_set('cron_last_ok_at', sf_now());
        sf_meta_set('cron_last_ms', (string)$ms);
        return ['ran' => $ran, 'failed' => $failed, 'ms' => $ms];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** מריץ משימה אחת (שכבר נשלפה מהתור). true = הצליחה. */
function sf_job_exec(array $job): bool
{
    $attempt = (int)$job['attempts'] + 1;
    sf_exec("UPDATE jobs SET status = 'running', attempts = ?, started_at = ?, finished_at = NULL WHERE id = ?", [$attempt, sf_now(), $job['id']]);
    try {
        $kind = sf_job_kinds()[$job['kind']] ?? throw new RuntimeException('סוג משימה לא רשום');
        ($kind['handler'])(json_decode((string)$job['payload'], true) ?: [], $job);
        sf_exec("UPDATE jobs SET status = 'done', finished_at = ?, last_error = NULL WHERE id = ?", [sf_now(), $job['id']]);
        return true;
    } catch (Throwable $e) {
        $final = $attempt >= (int)$job['max_attempts'];
        $err = mb_substr(get_class($e) . ' ב-' . basename($e->getFile()) . ':' . $e->getLine() . ($e instanceof SfError ? ' (' . $e->errorCode . ')' : ''), 0, 300);
        sf_exec('UPDATE jobs SET status = ?, run_after = ?, finished_at = ?, last_error = ? WHERE id = ?', [$final ? 'failed' : 'pending',
            sf_iso(sf_time() + (SF_JOB_BACKOFF[$attempt - 1] ?? end(SF_JOB_BACKOFF))), $final ? sf_now() : null, $err, $job['id']]);
        if (function_exists('devloop_log_write')) {
            devloop_log_write('error', 'job', 'job.failed', ['message' => $job['kind'] . ': ' . $err, 'data' => ['job' => $job['pid'], 'attempt' => $attempt]]);
        }
        return false;
    }
}

/**
 * הרצה מיידית של משימה אחת שממתינה בתור (ייבוא: המנהל לא מחכה ל-cron). אותה נעילה של sf_jobs_run — אם סבב רץ עכשיו,
 * חוזר ב-'locked' והמשימה תרוץ בסבב. מחזיר: done · failed · locked · not_pending.
 */
function sf_job_run_now(string $pid): string
{
    $dir = sf_data_dir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $lock = @fopen($dir . '/cron.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        return 'locked';
    }
    try {
        $job = sf_row("SELECT * FROM jobs WHERE pid = ? AND status = 'pending'", [$pid]);
        if (!$job) return 'not_pending';
        return sf_job_exec($job) ? 'done' : 'failed';
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ── האסימון של cron.php ──────────────────────────────────────

function sf_cron_token_file(): string
{
    return sf_data_dir() . '/cron.token';
}

/** האסימון — נוצר בפעם הראשונה (data/cron.token, לא בגיט, מוחרג בפריסה). */
function sf_cron_token(): string
{
    $f = sf_cron_token_file();
    $t = is_file($f) ? trim((string)file_get_contents($f)) : '';
    if (preg_match('/^[a-f0-9]{48}$/', $t)) return $t;
    return sf_cron_token_write();
}

function sf_cron_token_write(): string
{
    $dir = sf_data_dir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $t = bin2hex(random_bytes(24));
    if (@file_put_contents(sf_cron_token_file(), $t, LOCK_EX) === false) throw new SfError('storage');
    @chmod(sf_cron_token_file(), 0600);
    return $t;
}

function sf_cron_rotate(): array
{
    return sf_tx(function () {
        sf_audit('cron.token_rotate', 'cron', null);
        sf_cron_token_write();
        return ['cron' => sf_cron_info()];
    });
}

function sf_cron_url(): string
{
    return sf_self_url('cron.php?token=' . sf_cron_token());
}

function sf_cron_info(): array
{
    $url = sf_cron_url();
    return ['url' => $url, 'command' => 'curl -s "' . $url . '" > /dev/null', 'every' => '*/5 * * * *',
        'last_run_at' => sf_meta('cron_last_run_at'), 'last_ok_at' => sf_meta('cron_last_ok_at'), 'last_ms' => (int)sf_meta('cron_last_ms', '0')];
}

/** מה ש-cron.php עושה: [status, body]. אסימון חסר או שגוי — 404, כאילו אין כאן כלום. */
function sf_cron_handle(mixed $token): array
{
    if (!is_string($token) || $token === '' || !hash_equals(sf_cron_token(), $token)) return [404, ['ok' => false]];
    sf_db();
    return [200, ['ok' => true] + sf_jobs_run()];
}

// ── מסך המפתח ────────────────────────────────────────────────

function sf_jobs_view(): array
{
    $counts = array_map('intval', array_column(sf_rows('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status'), 'n', 'status'));
    $kinds = [];
    foreach (sf_job_kinds() as $kind => $k) {
        $kinds[] = ['kind' => $kind, 'label' => $k['label'], 'daily' => $k['daily'],
            'last_done_at' => sf_val("SELECT MAX(finished_at) FROM jobs WHERE kind = ? AND status = 'done'", [$kind])];
    }
    return [
        'cron' => sf_cron_info(),
        'counts' => (object)$counts,
        'kinds' => $kinds,
        'jobs' => array_map(fn($j) => ['pid' => $j['pid'], 'kind' => $j['kind'], 'status' => $j['status'], 'attempts' => (int)$j['attempts'],
            'max_attempts' => (int)$j['max_attempts'], 'run_after' => $j['run_after'], 'finished_at' => $j['finished_at'], 'last_error' => $j['last_error']],
            sf_rows("SELECT * FROM jobs ORDER BY CASE WHEN status = 'failed' THEN 0 ELSE 1 END, id DESC LIMIT 50")),
    ];
}

function sf_job_retry(mixed $pid): array
{
    return sf_tx(function () use ($pid) {
        $j = (sf_is_pid($pid) ? sf_row('SELECT * FROM jobs WHERE pid = ?', [$pid]) : null) ?? sf_fail('not_found');
        if (!in_array($j['status'], ['failed', 'cancelled'], true)) sf_fail('state');
        sf_exec("UPDATE jobs SET status = 'pending', attempts = 0, run_after = ?, finished_at = NULL WHERE id = ?", [sf_now(), $j['id']]);
        sf_audit('job.retry', 'job', $j['pid'], ['kind' => $j['kind'], 'from' => $j['status']]);
        return [];
    });
}

function sf_job_cancel(mixed $pid): array
{
    return sf_tx(function () use ($pid) {
        $j = (sf_is_pid($pid) ? sf_row('SELECT * FROM jobs WHERE pid = ?', [$pid]) : null) ?? sf_fail('not_found');
        if (!in_array($j['status'], ['pending', 'failed'], true)) sf_fail('state');
        // cancelled — מצב חמישי מעבר ל-§4, כדי שמשימה שבוטלה לא תיספר כ"נכשלה" בבדיקת התקינות (team/decisions.md)
        sf_exec("UPDATE jobs SET status = 'cancelled', finished_at = ? WHERE id = ?", [sf_now(), $j['id']]);
        sf_audit('job.cancel', 'job', $j['pid'], ['kind' => $j['kind'], 'from' => $j['status']]);
        return [];
    });
}

// ── השירות של הפרוסה הזו ─────────────────────────────────────

sf_job_register('maintenance.cleanup', 'ניקוי יומי: sessions שפגו, ומשימות שהסתיימו לפני יותר מ-30 יום', function (): void {
    sf_exec('DELETE FROM staff_sessions WHERE expires_at <= ?', [sf_now()]);
    sf_exec("DELETE FROM jobs WHERE status IN ('done', 'cancelled') AND finished_at < ?", [sf_iso(sf_time() - 30 * 86400)]);
}, daily: true);
