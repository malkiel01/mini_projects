<?php
/**
 * עבודות מתוזמנות (פרק 9.1). מהשרת, כל 5 דקות: php …/payments/cron.php
 * כל עבודה בטוחה להרצה כפולה — נעילה רכה במסד, ותנועות בארנק רק בטרנזקציה.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/lib/jobs.php';

$r = tick('cron', true);
echo gmdate('c', now()) . ' ' . json_encode($r) . "\n";
