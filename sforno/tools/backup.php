<?php
/**
 * הגיבויים מהשורה (אפיון 10 §4). רק CLI — מהדפדפן: 404 (וגם tools/.htaccess חוסם).
 *
 *   php sforno/tools/backup.php list                 הגיבויים הקיימים
 *   php sforno/tools/backup.php make                 גיבוי עכשיו (data/backups/)
 *   php sforno/tools/backup.php verify [pid]         בדיקת שחזור: הגיבוי (האחרון) למסד זמני, integrity_check, ספירות, מפתח
 *   php sforno/tools/backup.php restore-to <dir> [pid]   חילוץ ובדיקה לתיקייה (בלי לגעת בנתונים החיים)
 *   php sforno/tools/backup.php apply [pid] --yes    **שחזור אמיתי** — לפי dev/restore.md: מצב תחזוקה (503), גיבוי בטיחות,
 *                                                    החלפה — הכול או כלום; מה שהוחלף נשמר ב-data/.replaced-<זמן>/
 *   php sforno/tools/backup.php import <file.sfbk>   גיבוי מוצפן שהורד (💾) → מפוענח, נבדק ונרשם כגיבוי בשרת (ואז verify / apply)
 *   php sforno/tools/backup.php decrypt <file.sfbk> <out.zip>   רק פענוח — בכל מחשב עם PHP ו-sodium, בלי מסד
 * הסיסמה של קובץ מוצפן: נשאלת בשורה (בלי הד), או מ-SF_BACKUP_PASSWORD.
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
$password = function (): string {
    $pw = getenv('SF_BACKUP_PASSWORD');
    if (is_string($pw) && $pw !== '') return $pw;
    fwrite(STDERR, 'הסיסמה של הגיבוי: ');
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) @shell_exec('stty -echo');
    $pw = rtrim((string)fgets(STDIN), "\r\n");
    if ($tty) { @shell_exec('stty echo'); fwrite(STDERR, "\n"); }
    return $pw;
};
$out = function (mixed $v): void { echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; };

try {
    if (in_array($cmd, ['list', 'make', 'verify', 'restore-to', 'apply', 'import'], true)) sf_db();
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
        case 'decrypt':
            if (count($args) < 3 || file_exists($args[2])) { fwrite(STDERR, "decrypt <קובץ.sfbk> <קובץ-חדש.zip>\n"); exit(1); }
            sf_backup_decrypt($args[1], $args[2], $password());
            echo "✓ פוענח ל-{$args[2]}\n";
            exit(0);
        case 'import':
            if (count($args) < 2 || !is_file($args[1])) { fwrite(STDERR, "import <קובץ.sfbk>\n"); exit(1); }
            $b = sf_backup_import($args[1], $password());
            echo "✓ נרשם כגיבוי {$b['pid']} ({$b['file']}). הבא: verify {$b['pid']} ← apply {$b['pid']} --yes\n";
            exit(0);
        default:
            echo "שימוש: list | make | verify [pid] | restore-to <dir> [pid] | apply [pid] --yes | import <file.sfbk> | decrypt <file.sfbk> <out.zip>\n";
            exit($cmd === 'help' ? 0 : 1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, '✗ ' . ($e instanceof SfError ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage()) . "\n");
    exit(1);
}
