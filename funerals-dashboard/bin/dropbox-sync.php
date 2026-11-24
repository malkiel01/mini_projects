<?php
declare(strict_types=1);

/**
 * גיבוי ל-webhook: משימה מתוזמנת כל 5 דקות (CRONS ב-deploy.yml — שורה של הבקרה):
 *   funerals-dashboard-dropbox|*\/5 * * * *|funerals-dashboard/bin/dropbox-sync.php|funerals-dashboard/data/dropbox-sync.log
 * עוברת על כל הארגונים המחוברים וממשיכה מה-cursor. רק מ-CLI — דרך HTTP → 404.
 * הפלט (ליומן) — מספרים ותגיות בלבד, בלי סודות ובלי שמות קבצים.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/dropbox/lib.php';

$pdo = db();
$started = microtime(true);
foreach (dbxConnectedOrgs($pdo) as $orgId) {
    if (microtime(true) - $started > 240) { echo gmdate('c') . " stop: time budget\n"; break; }
    try {
        $r = dbxSync($pdo, $orgId, 45.0);
    } catch (Throwable $e) {
        dbxOrgError($pdo, $orgId, 'cron_sync');
        $r = ['ok' => false, 'error' => 'exception'];
    }
    echo gmdate('c') . ' org=' . $orgId . ' ' . json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
}
