<?php
/**
 * קליטת דיווחי מורים ממקור מורשה — POST api/report-ingest.php.
 *
 * מי קורא: מערכת מורשית בלבד, עם אסימון מקובץ מחוץ לאתר (ingest.token_file). זו זהות של המקור, לא של לומד.
 * אין כאן עוגיות ולא זהות דפדפן, ולכן אין סיכון CSRF; בקשה עם Origin או Sec-Fetch-Site נדחית בכל מקרה.
 * כל דיווח עובר את מאמת הדיווח (validate-report.php), נבנה לגוף של action=report, ונכתב דרך אותו נתיב כמו
 * הכתיבה של המנהל (_ap_write_action): נעילה, יומן, כשל מלא-או-כלום לכל דיווח בודד, ודחיית כפילויות.
 * אפיון: docs/spec/report-ingest.md
 */

declare(strict_types=1);

require_once __DIR__ . '/academy-source.php';
require_once __DIR__ . '/progress-api.php';
require_once __DIR__ . '/validate-report.php';

const INGEST_MAX_REPORTS = 50;

/**
 * אסימון המקור מ-ingest.token_file בלבד (אותם כללים כמו אסימון GitHub: מחוץ לאתר, לא קריא לכולם, ערך חזק).
 * מחזיר null בכל כשל — בלי נתיב ובלי ערך, והתשובה לחיצוני היא 503 גנרי.
 * $reader — להזרקה בבדיקות.
 */
function academy_ingest_token(array $config, ?callable $reader = null): ?string {
    $ing = (array) ($config['ingest'] ?? []);
    if (array_key_exists('token', $ing)) return null;   // אסימון בקונפיג אסור
    try {
        return academy_github_token(['token_file' => $ing['token_file'] ?? ''], $reader);
    } catch (AcademySourceError $e) {
        error_log('cyber-academy ingest: ' . $e->reason);   // סיבה פנימית בלבד
        return null;
    }
}

/** @return array{0:int,1:array} */
function academy_api_report_ingest(string $method, array $config, string $contentType, string $raw, array $opts = []): array {
    if (strtoupper($method) !== 'POST') return _ap_fail(405, 'method_not_allowed', 'POST בלבד');
    $media = strtolower(trim(explode(';', $contentType, 2)[0]));
    if ($media !== 'application/json') return _ap_fail(415, 'unsupported_media_type', 'נדרש JSON');
    $server = $opts['server'] ?? $_SERVER;
    if (isset($server['HTTP_ORIGIN']) || isset($server['HTTP_SEC_FETCH_SITE'])) {
        return _ap_fail(403, 'forbidden', 'קליטה מותרת רק ממקור מורשה, לא מדפדפן');
    }

    $token = academy_ingest_token($config, $opts['reader'] ?? null);
    if ($token === null) return _ap_fail(503, 'ingest_unavailable', 'קליטת דיווחים אינה מוגדרת');
    $auth = (string) ($server['HTTP_AUTHORIZATION'] ?? '');
    $given = preg_match('/^Bearer\s+(\S+)$/', $auth, $m) ? $m[1] : '';
    if ($given === '' || !hash_equals($token, $given)) return _ap_fail(401, 'unauthorized', 'אסימון מקור חסר או שגוי');

    $b = json_decode($raw, true);
    if (!is_array($b)) return _ap_fail(400, 'bad_request', 'גוף הבקשה אינו JSON תקין');
    $learnerId = (string) ($b['learnerId'] ?? '');
    if (!preg_match(ACADEMY_V2_ID, $learnerId)) return _ap_fail(400, 'bad_request', 'מזהה לומד לא תקין');
    $reports = $b['reports'] ?? null;
    if (!is_array($reports) || $reports === [] || count($reports) > INGEST_MAX_REPORTS) {
        return _ap_fail(400, 'bad_request', 'נדרש מערך reports (1–' . INGEST_MAX_REPORTS . ')');
    }

    $now = $opts['now'] ?? gmdate('Y-m-d\TH:i:s\Z');
    $results = [];
    $pending = [];   // [index, payload]
    foreach ($reports as $i => $r) {
        $errs = academy_validate_report(is_array($r) ? $r : null, $opts['catalog'] ?? null, []);
        if ($errs !== []) {
            $results[$i] = ['index' => $i, 'status' => 'invalid', 'errors' => $errs];
            continue;
        }
        $pending[] = [$i, academy_report_to_progress_payload($r, $learnerId)];
    }
    if ($pending === []) return [200, ['ok' => true, 'learnerId' => $learnerId, 'results' => array_values($results)]];

    $audit = ['at' => $now, 'by' => 'ingest', 'learnerId' => $learnerId];
    try {
        $dir = academy_progress_dir($config);
        $hooks = array_intersect_key($opts, ['rename' => 1, 'auditWrite' => 1]);   // בבדיקות בלבד
        return academy_progress_locked($dir, function () use ($pending, $dir, $now, $audit, $hooks, &$results, $learnerId) {
            foreach ($pending as [$i, $payload]) {
                [$st, $body] = _ap_write_action($payload, $dir, $learnerId, $now, $audit, $hooks);
                $rid = $payload['report']['id'];
                if ($st === 200) {
                    $results[$i] = ['index' => $i, 'status' => !empty($body['duplicate']) ? 'duplicate' : 'stored', 'reportId' => $rid];
                } else {
                    $results[$i] = ['index' => $i, 'status' => 'rejected', 'reportId' => $rid, 'code' => $body['code'] ?? 'internal'];
                }
            }
            ksort($results);
            return [200, ['ok' => true, 'learnerId' => $learnerId, 'results' => array_values($results)]];
        });
    } catch (AcademyProgressError $e) {
        if ($e->errorCode === 'progress_unavailable') return _ap_fail(503, 'progress_unavailable', 'אחסון ההתקדמות לא זמין');
        // אין נתיב ואין תוכן. שליחה חוזרת בטוחה: דיווחים שנכתבו כבר ייחשבו כפילות
        return _ap_fail(503, 'progress_unavailable', 'שמירה נכשלה — אפשר לשלוח שוב');
    }
}
