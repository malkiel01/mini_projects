<?php
/**
 * הלוגיקה של api/snapshot.php, כפונקציות טהורות שאפשר לבדוק בלי שרת.
 * מקור → אימות → מודל. מחזירה [status, body]; הקובץ ב-api/ רק מדפיס.
 *
 * כל כשל מחזיר `code` יציב, וה-UI מציג לכל אחד מסך משלו:
 *   forbidden · method_not_allowed · source_disabled · source_unauthorized · source_not_found
 *   · source_unavailable · contract_invalid · contract_version · internal
 * (offline ו-loading — רק בצד הלקוח.)
 */

declare(strict_types=1);

require_once __DIR__ . '/academy-source.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/model.php';
require_once __DIR__ . '/validate-v2.php';
require_once __DIR__ . '/model-v2.php';
require_once __DIR__ . '/access.php';

const ACADEMY_SOURCE_HTTP = [
    'source_disabled' => 503, 'source_unauthorized' => 502, 'source_not_found' => 502,
    'source_unavailable' => 502, 'contract_invalid' => 422,
];

/** נקודת הכניסה של ה-API: שיטה + קונפיג + פרמטרים. קריאה בלבד — כל שיטה שאינה GET/HEAD נדחית. */
function academy_api_handle(string $method, array $config, array $query = [], ?AcademySource $source = null): array {
    if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
        return [405, ['ok' => false, 'code' => 'method_not_allowed', 'error' => 'הדשבורד לקריאה בלבד']];
    }
    return academy_api_snapshot($config, $query, $source);
}

/**
 * @param array $query  רק demo=empty נתמך, ורק במקור local. שום פרמטר לא בוחר מקור ולא משנה isSample.
 * @param AcademySource|null $source  להזרקה בבדיקות
 * @return array{0:int, 1:array}
 */
function academy_api_snapshot(array $config, array $query = [], ?AcademySource $source = null): array {
    if (!academy_can('snapshot.read')) return [403, ['ok' => false, 'code' => 'forbidden', 'error' => 'אין הרשאה לצפות בדשבורד']];

    $token = (string) ($config['github']['token'] ?? '');
    $contract = ['schemaVersion' => ACADEMY_SCHEMA_VERSION, 'commit' => ACADEMY_SCHEMA_COMMIT];
    $fail = fn(int $st, string $code, string $msg, array $meta, array $extra = []) =>
        [$st, ['ok' => false, 'code' => $code, 'error' => academy_redact($msg, $token)] + $extra + $meta];

    $demo = ($query['demo'] ?? '') === 'empty' ? 'empty' : '';
    try {
        $source ??= academy_source($config, $demo);
    } catch (AcademySourceError $e) {
        return $fail(ACADEMY_SOURCE_HTTP[$e->errorCode] ?? 502, $e->errorCode, $e->getMessage(),
            ['source' => (string) ($config['source'] ?? 'github'), 'isSample' => false, 'contract' => $contract]);
    }
    $meta = ['source' => $source->name(), 'isSample' => $source->isSample(), 'contract' => $contract];

    try {
        $data = $source->fetch();
    } catch (AcademySourceError $e) {
        return $fail(ACADEMY_SOURCE_HTTP[$e->errorCode] ?? 502, $e->errorCode, $e->getMessage(), $meta);
    } catch (Throwable $e) {
        return $fail(502, 'source_unavailable', 'לא ניתן לקרוא את נתוני האקדמיה: ' . $e->getMessage(), $meta);
    }
    $meta['sourceInfo'] = $source->info();

    // גרסת חוזה לא נתמכת — מסך משלה, לפני שאר השגיאות (שהן תוצאה שלה)
    $supported = [ACADEMY_SCHEMA_VERSION, ACADEMY_SCHEMA_V2];
    $version = is_array($data) ? ($data['schemaVersion'] ?? ACADEMY_SCHEMA_VERSION) : ACADEMY_SCHEMA_VERSION;
    if (!in_array($version, $supported, true)) {
        $got = is_scalar($version) ? (string) $version : json_encode($version);
        return $fail(422, 'contract_version', "גרסת החוזה \"$got\" אינה נתמכת — הדשבורד קורא רק " . implode(' או ', $supported),
            $meta, ['receivedVersion' => academy_redact(mb_substr($got, 0, 40), $token)]);
    }
    $v2 = $version === ACADEMY_SCHEMA_V2;
    $invalid = fn(array $errors) => $fail(422, 'contract_invalid', 'ה-snapshot אינו תואם לחוזה ' . ($v2 ? 'v2' : 'v1') . ' — לא מוצג', $meta, [
        'errors' => array_slice(array_map(fn($e) => academy_redact($e, $token), $errors), 0, 50), 'errorCount' => count($errors)]);
    $errors = $v2 ? academy_validate_v2($data) : academy_validate($data);
    if ($errors) return $invalid($errors);
    $meta['contract'] = $v2 ? ['schemaVersion' => ACADEMY_SCHEMA_V2, 'commit' => null, 'proposal' => true] : $contract;
    if ($v2 && $source->name() === 'github') {
        // החלטת מנהל 00: מתחילים מסילבוס בלבד, ורק מאושר. בלי נתוני לומד.
        $data = academy_v2_curriculum_only($data);
        $errors = academy_validate_v2($data);
        if ($errors) return $invalid($errors);
        $meta['filter'] = 'approved_curriculum_only';
    }
    return [200, ['ok' => true] + $meta + ['model' => $v2 ? academy_model_v2($data) : academy_model($data)]];
}

/**
 * GET api/curriculum.php — "תוכנית האקדמיה" בלבד (Issue #6, משימה 4): משתמש מחובר ומורשה, בשרת.
 * רק ממאגר האקדמיה (חוזה v2, github.curriculum_path), רק סילבוס מאושר, ובלי שום נתון לומד — לא כשירויות, משימות,
 * הערכות, ראיות, ציר זמן או issues. אין דוגמה מקומית: כשהמקור כבוי → source_disabled.
 */
function academy_api_curriculum(string $method, array $config, ?AcademySource $source = null, array $opts = []): array {
    // $opts — לבדיקות בלבד: server/sapi (זהות), reader (קובץ האסימון), transport (HTTP)
    if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
        return [405, ['ok' => false, 'code' => 'method_not_allowed', 'error' => 'קריאה בלבד']];
    }
    $role = academy_role($opts['server'] ?? null, $opts['sapi'] ?? PHP_SAPI);
    if ($role === null || !academy_can('curriculum.read', $role) || !academy_can('snapshot.read', $role)) {
        return [403, ['ok' => false, 'code' => 'forbidden', 'error' => 'אין הרשאה']];
    }
    $token = (string) ($config['github']['token'] ?? '');
    $meta = ['source' => 'github', 'isSample' => false];
    $fail = fn(int $st, string $code, string $msg) => [$st, ['ok' => false, 'code' => $code, 'error' => academy_redact($msg, $token)] + $meta];
    try {
        if (!$source) {
            // source נשאר כמו בקונפיג: נדלק רק כשהשרת קבע במפורש source="github" (והדגלים). אין דוגמה.
            $src = academy_source(array_replace($config, ['contract' => 'v2', 'fallback_to_sample' => false]), '',
                ACADEMY_GITHUB_PHASE_ALLOWED, $opts['reader'] ?? null, $opts['transport'] ?? null);
            if (!$src instanceof GithubAcademySource) return $fail(503, 'source_disabled', 'החיבור למאגר האקדמיה כבוי');
            $source = $src;
        }
        $data = $source->fetch();
    } catch (AcademySourceError $e) {
        return $fail(ACADEMY_SOURCE_HTTP[$e->errorCode] ?? 502, $e->errorCode, $e->getMessage());
    } catch (Throwable $e) {
        return $fail(502, 'source_unavailable', 'לא ניתן לקרוא את תוכנית האקדמיה: ' . $e->getMessage());
    }
    if (!is_array($data) || ($data['schemaVersion'] ?? null) !== ACADEMY_SCHEMA_V2) {
        return $fail(422, 'contract_version', 'תוכנית האקדמיה חייבת להיות בחוזה ' . ACADEMY_SCHEMA_V2);
    }
    $errors = academy_validate_v2($data);
    if (!$errors) { $data = academy_v2_curriculum_only($data); $errors = academy_validate_v2($data); }
    if ($errors) {
        return [422, ['ok' => false, 'code' => 'contract_invalid', 'error' => 'תוכנית האקדמיה אינה תואמת לחוזה v2',
            'errors' => array_slice(array_map(fn($e) => academy_redact($e, $token), $errors), 0, 50), 'errorCount' => count($errors)] + $meta];
    }
    $m = academy_model_v2($data);
    return [200, ['ok' => true] + $meta + ['sourceInfo' => $source->info(), 'filter' => 'approved_curriculum_only', 'curriculum' => [
        'curriculum' => $m['curriculum'], 'counts' => $m['counts'],
        'stages' => $m['stages'], 'tracks' => array_map(fn($t) => array_diff_key($t, ['measure' => 1]), $m['tracks']),
        'modules' => array_map(fn($md) => array_diff_key($md, ['measure' => 1]), $m['modules']),
    ]]];
}
