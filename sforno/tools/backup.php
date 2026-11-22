<?php
/**
 * הגיבויים מהשורה (אפיון 10 §4). רק CLI — מהדפדפן: 404 (וגם tools/.htaccess חוסם).
 *
 *   php sforno/tools/backup.php list                 הגיבויים הקיימים
 *   php sforno/tools/backup.php make                 גיבוי עכשיו (data/backups/)
 *   php sforno/tools/backup.php verify [pid]         בדיקת שחזור: הגיבוי (האחרון) למסד זמני, integrity_check, ספירות, מפתח
 *   php sforno/tools/backup.php restore-to <dir> [pid]   חילוץ ובדיקה לתיקייה (בלי לגעת בנתונים החיים)
 *   php sforno/tools/backup.php apply [pid] --yes    **שחזור אמיתי** — לפי dev/restore.md: גיבוי בטיחות, ואז החלפה;
 *                                                    מה שהוחלף נשמר ב-data/.replaced-<זמן>/ (שום דבר לא נמחק)
 * יציאה 0 = הצליח, 1 = נכשל. SF_DATA_DIR=<תיקייה> — לעבוד על העתק של data/ (בדיקה), לא על החי.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// תיקיית נתונים אחרת (סביבת בדיקה / העתק): SF_DATA_DIR=/path php sforno/tools/backup.php …
$dd = getenv('SF_DATA_DIR');
if (is_string($dd) && $dd !== '') define('SF_DATA_DIR', $dd);

require_once dirname(__DIR__) . '/lib/app.php';

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--yes'));
$yes = in_array('--yes', $argv, true);
$cmd = $args[0] ?? 'help';
$out = function (mixed $v): void { echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; };

try {
    if (in_array($cmd, ['list', 'make', 'verify', 'restore-to', 'apply'], true)) sf_db();
    switch ($cmd) {
        case 'list':
            $out(array_map(fn($b) => ['pid' => $b['pid'], 'file' => $b['file'], 'kind' => $b['kind'], 'mb' => round($b['bytes'] / 1048576, 2),
                'created_at' => $b['created_at'], 'verified' => $b['verified_at'] ? ($b['verify_ok'] ? 'ok ' : 'FAILED ') . $b['verified_at'] : null], sf_dev_backups()['backups']));
            exit(0);
        case 'make':
            $b = sf_backup_make('manual');
            echo "✓ {$b['file']} (" . round($b['bytes'] / 1048576, 2) . "MB)\n";
            exit(0);
        case 'verify':
            $r = sf_backup_verify($args[1] ?? null);
            foreach ($r['checks'] as $c) echo ($c['ok'] ? '✓ ' : '✗ ') . $c['text'] . "\n";
            exit($r['passed'] ? 0 : 1);
        case 'restore-to':
            $dir = $args[1] ?? '';
            if ($dir === '' || file_exists($dir)) { fwrite(STDERR, "תיקיית יעד חדשה (שאינה קיימת) נדרשת\n"); exit(1); }
            $r = sf_backup_verify($args[2] ?? null, $dir);
            foreach ($r['checks'] as $c) echo ($c['ok'] ? '✓ ' : '✗ ') . $c['text'] . "\n";
            echo $r['passed'] ? "✓ חולץ ל-$dir\n" : "✗ נכשל\n";
            exit($r['passed'] ? 0 : 1);
        case 'apply':
            if (!$yes) { fwrite(STDERR, "שחזור מחליף את הנתונים החיים. לקרוא את dev/restore.md, ולהריץ שוב עם --yes\n"); exit(1); }
            $out(sf_backup_apply($args[1] ?? null));
            echo "✓ שוחזר. הבא: לפתוח את האפליקציה, 🩺 בדיקות — הכול ירוק (dev/restore.md, שלב 5)\n";
            exit(0);
        default:
            echo "שימוש: list | make | verify [pid] | restore-to <dir> [pid] | apply [pid] --yes\n";
            exit($cmd === 'help' ? 0 : 1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, '✗ ' . ($e instanceof SfError ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage()) . "\n");
    exit(1);
}
