<?php
/**
 * שירותי רקע (אפיון 01 §9.5). אין דמון: טבלת jobs + cron.php (מתזמן cPanel), ובנוסף סבב קצר אחרי בקשת API רגילה
 * כשהמתזמן לא רץ 10 דקות — כך המערכת עובדת גם לפני שהמתזמן הוגדר.
 *
 * שירות חדש: apr_job_register('kind', 'מה הוא עושה', handler, כל כמה שניות). ה-handler מקבל את ה-payload ומחזיר
 * 'done' / 'partial' / 'needs_action' (או כלום = done); חריגה = ניסיון שנכשל.
 * last_error הוא הודעה טכנית — בלי תוכן של תיק.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

const APR_JOB_BACKOFF = [60, 300, 1800];     // דקה, 5, 30
const APR_JOB_STUCK = 600;                   // "running" מעל 10 דקות — נהרגה באמצע
const APR_CRON_STALE = 600;                  // המתזמן לא רץ 10 דקות → סבב גיבוי מבקשה רגילה

function apr_job_register(string $kind, string $label, callable $handler, int $everySec = 0): void
{
    $GLOBALS['apr_job_kinds'][$kind] = ['label' => $label, 'handler' => $handler, 'every' => $everySec];
}

function apr_job_kinds(): array
{
    return $GLOBALS['apr_job_kinds'] ?? [];
}

/** מכניס משימה לתור. dedupe — אותה משימה לא נכנסת פעמיים. מחזיר pid, או null כשכבר קיימת. */
function apr_job_add(string $kind, array $payload = [], ?string $dedupe = null, ?int $office = null, int $delay = 0, int $maxAttempts = 3): ?string
{
    if ($dedupe !== null && apr_val('SELECT 1 FROM jobs WHERE dedupe_key = ?', [$dedupe])) return null;
    $pid = apr_pid();
    try {
        apr_insert('jobs', ['pid' => $pid, 'office_id' => $office, 'kind' => $kind, 'payload' => json_encode((object)$payload, JSON_UNESCAPED_UNICODE),
            'dedupe_key' => $dedupe, 'max_attempts' => $maxAttempts, 'run_after' => apr_iso(apr_time() + $delay), 'created_at' => apr_now()]);
    } catch (PDOException $e) {
        if ($dedupe !== null && str_contains($e->getMessage(), 'UNIQUE')) return null;
        throw $e;
    }
    return $pid;
}

/** שירותים קבועים: לכל kind עם מחזור — משימה אחת לכל חלון זמן. */
function apr_jobs_schedule(): void
{
    foreach (apr_job_kinds() as $kind => $k) {
        if ($k['every'] > 0) apr_job_add($kind, [], $kind . ':' . intdiv(apr_time(), $k['every']));
    }
}

/**
 * סבב: מריץ משימות pending שמועדן הגיע, עד $budget שניות. נעילה בקובץ — שני סבבים לא רצים במקביל; השני מדלג.
 * מחזיר סיכום: ['ran' => n, 'failed' => n, 'ms' => …] או ['skipped' => 'locked'].
 */
function apr_jobs_run(float $budget = 25.0, string $source = 'cron'): array
{
    $dir = apr_data_dir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $lock = @fopen($dir . '/cron.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['skipped' => 'locked'];
    $t0 = microtime(true);
    try {
        apr_meta_set('cron_last_run_at', apr_now());
        // משימה שנהרגה באמצע (מגבלת זמן) חוזרת לתור; הניסיון כבר נספר
        apr_exec("UPDATE jobs SET status = CASE WHEN attempts >= max_attempts THEN 'failed' ELSE 'pending' END,
                last_error = 'הופסקה באמצע (מגבלת זמן)', finished_at = CASE WHEN attempts >= max_attempts THEN ? ELSE NULL END
            WHERE status = 'running' AND started_at < ?", [apr_now(), apr_iso(apr_time() - APR_JOB_STUCK)]);
        apr_jobs_schedule();

        $ran = $failed = 0;
        while (microtime(true) - $t0 < $budget) {
            $job = apr_row("SELECT * FROM jobs WHERE status = 'pending' AND run_after <= ? ORDER BY run_after, id LIMIT 1", [apr_now()]);
            if (!$job) break;
            apr_exec("UPDATE jobs SET status = 'running', attempts = attempts + 1, started_at = ?, finished_at = NULL WHERE id = ?", [apr_now(), $job['id']]);
            $attempt = (int)$job['attempts'] + 1;
            try {
                $kind = apr_job_kinds()[$job['kind']] ?? throw new RuntimeException('סוג משימה לא רשום');
                $result = ($kind['handler'])(json_decode($job['payload'], true) ?: [], $job);
                $status = in_array($result, ['partial', 'needs_action'], true) ? $result : 'done';
                apr_exec('UPDATE jobs SET status = ?, finished_at = ?, last_error = NULL WHERE id = ?', [$status, apr_now(), $job['id']]);
                $ran++;
            } catch (Throwable $e) {
                $failed++;
                $final = $attempt >= (int)$job['max_attempts'];
                // הודעה טכנית בלבד: סוג החריגה והמיקום — לא ההודעה עצמה, שעלולה לשאת תוכן
                $err = mb_substr(get_class($e) . ' ב-' . basename($e->getFile()) . ':' . $e->getLine() . ($e instanceof AprError ? ' (' . $e->errorCode . ')' : ''), 0, 300);
                apr_exec('UPDATE jobs SET status = ?, run_after = ?, finished_at = ?, last_error = ? WHERE id = ?', [$final ? 'failed' : 'pending',
                    apr_iso(apr_time() + (APR_JOB_BACKOFF[$attempt - 1] ?? 1800)), $final ? apr_now() : null, $err, $job['id']]);
                if (function_exists('devloop_log_write')) {
                    devloop_log_write('error', 'job', 'job.failed', ['message' => $job['kind'] . ': ' . $err, 'data' => ['job' => $job['pid'], 'attempt' => $attempt]]);
                }
            }
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        apr_meta_set('cron_last_ok_at', apr_now());
        apr_meta_set('cron_last_ms', (string)$ms);
        apr_meta_set('cron_last_source', $source);
        return ['ran' => $ran, 'failed' => $failed, 'ms' => $ms];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** סבב הגיבוי: האם המתזמן לא רץ זמן רב מדי. */
function apr_cron_stale(): bool
{
    $last = apr_meta('cron_last_run_at');
    return $last === null || strtotime($last) < apr_time() - APR_CRON_STALE;
}

function apr_cron_token(): string
{
    return apr_derived_token('cron');
}

// ── מסך המפתח ────────────────────────────────────────────────

function apr_jobs_view(string $cronUrl): array
{
    $counts = array_column(apr_rows('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status'), 'n', 'status');
    $lastRun = array_column(apr_rows("SELECT kind, MAX(finished_at) AS at FROM jobs WHERE status IN ('done', 'partial') GROUP BY kind"), 'at', 'kind');
    $kinds = [];
    foreach (apr_job_kinds() as $kind => $k) $kinds[] = ['kind' => $kind, 'label' => $k['label'], 'every' => $k['every'], 'last_done_at' => $lastRun[$kind] ?? null];
    return [
        'cron' => ['last_run_at' => apr_meta('cron_last_run_at'), 'last_ok_at' => apr_meta('cron_last_ok_at'), 'last_ms' => (int)apr_meta('cron_last_ms', '0'),
            'last_source' => apr_meta('cron_last_source'), 'url' => $cronUrl],
        'counts' => (object)array_map('intval', $counts),
        'kinds' => $kinds,
        'jobs' => array_map(fn($j) => ['pid' => $j['pid'], 'kind' => $j['kind'], 'status' => $j['status'], 'attempts' => (int)$j['attempts'],
            'max_attempts' => (int)$j['max_attempts'], 'run_after' => $j['run_after'], 'finished_at' => $j['finished_at'], 'last_error' => $j['last_error']],
            apr_rows("SELECT * FROM jobs ORDER BY (status IN ('failed', 'needs_action')) DESC, id DESC LIMIT 50")),
    ];
}

function apr_job_row(mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT * FROM jobs WHERE pid = ?', [$pid]) : null) ?? apr_fail('not_found');
}

function apr_job_retry(array $in): array
{
    $j = apr_job_row($in['job'] ?? null);
    if (!in_array($j['status'], ['failed', 'needs_action', 'cancelled'], true)) apr_fail('job_state');
    apr_exec("UPDATE jobs SET status = 'pending', attempts = 0, run_after = ?, finished_at = NULL WHERE id = ?", [apr_now(), $j['id']]);
    return [];
}

function apr_job_cancel(array $in): array
{
    $j = apr_job_row($in['job'] ?? null);
    if (!in_array($j['status'], ['pending', 'failed', 'needs_action'], true)) apr_fail('job_state');
    apr_exec("UPDATE jobs SET status = 'cancelled', finished_at = ? WHERE id = ?", [apr_now(), $j['id']]);
    return [];
}

// ── השירותים של הפרוסה הזו ───────────────────────────────────

apr_job_register('maintenance.cleanup', 'ניקוי: sessions שפגו, הזמנות שפגו, ומשימות שהסתיימו לפני יותר מ-30 יום', function (): string {
    apr_exec('DELETE FROM sessions WHERE expires_at <= ?', [apr_now()]);
    apr_exec("UPDATE invitations SET status = 'expired' WHERE status = 'pending' AND expires_at <= ?", [apr_now()]);
    apr_exec("DELETE FROM jobs WHERE status IN ('done', 'cancelled') AND finished_at < ?", [apr_iso(apr_time() - 30 * 86400)]);
    return 'done';
}, 3600);
