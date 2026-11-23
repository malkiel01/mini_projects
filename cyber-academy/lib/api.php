<?php
/**
 * הלוגיקה של api/snapshot.php, כפונקציה טהורה שאפשר לבדוק בלי שרת.
 * מקור → אימות → מודל. מחזירה [status, body] — הקובץ ב-api/ רק מדפיס.
 */

declare(strict_types=1);

require_once __DIR__ . '/academy-source.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/model.php';
require_once __DIR__ . '/access.php';

/**
 * @param array $config  קונפיג (academy_load_config)
 * @param array $query   פרמטרים מהבקשה — רק demo=empty נתמך, ורק במקור local
 * @param AcademySource|null $source  להזרקה בבדיקות
 * @return array{0:int, 1:array}
 */
function academy_api_snapshot(array $config, array $query = [], ?AcademySource $source = null): array {
    if (!academy_can('snapshot.read')) return [403, ['ok' => false, 'error' => 'אין הרשאה לצפות בדשבורד']];

    $demo = ($query['demo'] ?? '') === 'empty' ? 'empty' : '';
    $source ??= academy_source($config, $demo);
    $token = (string) ($config['github']['token'] ?? '');
    $meta = [
        'source' => $source->name(),
        'isSample' => $source->isSample(),
        'contract' => ['schemaVersion' => ACADEMY_SCHEMA_VERSION, 'commit' => ACADEMY_SCHEMA_COMMIT],
        'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    try {
        $data = $source->fetch();
    } catch (Throwable $e) {
        return [502, ['ok' => false, 'error' => academy_redact('לא ניתן לקרוא את נתוני האקדמיה: ' . $e->getMessage(), $token)] + $meta];
    }
    $errors = academy_validate($data);
    if ($errors) {
        return [422, ['ok' => false, 'error' => 'ה-snapshot אינו תואם לחוזה v1 — לא מוצג', 'errors' => array_slice(
            array_map(fn($e) => academy_redact($e, $token), $errors), 0, 50), 'errorCount' => count($errors)] + $meta];
    }
    return [200, ['ok' => true] + $meta + ['model' => academy_model($data)]];
}
