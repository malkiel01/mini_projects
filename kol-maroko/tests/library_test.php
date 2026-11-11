<?php
/**
 * בדיקות פרוסה A1 — ספריית ההקלטות (lib/library.php).
 *
 * הרצה:  php kol-maroko/tests/library_test.php
 * מסד זמני בתיקייה זמנית (KOL_DATA_DIR) — לא נוגע ב-data/. בלי Composer ובלי רשת.
 *
 * לחיבור ל-tests/run.php של F1: הקובץ מחזיר (return) מערך של שם → פונקציה שזורקת בכישלון.
 * כשמריצים אותו ישירות הוא מקים את המסד הזמני ומריץ את עצמו.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$direct = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__;
if ($direct) {
    $tmp = sys_get_temp_dir() . '/kol-a1-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700, true);
    define('KOL_DATA_DIR', $tmp);
    register_shutdown_function(function () use ($tmp) {
        foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($tmp);
    });
}
require_once __DIR__ . '/../lib/library.php';

/** מסד בדיקה: $n מילים בשלושת המצבים — באתר, בכלי (עותק / חדשה / חסרה), ובלי הקלטה. מחזיר מה שנזרע. */
function library_seed(int $n = 1500, string $host = 'maghreb.co.il'): array
{
    $pdo = db();
    $pdo->exec('DELETE FROM words; DELETE FROM files; DELETE FROM topics; DELETE FROM draft');
    setting_set('site_url', 'https://' . $host);
    $topics = ['אוכל', 'משפחה', 'ברכות', 'שוק', 'חגים'];
    $insW = $pdo->prepare('INSERT INTO words (id, word, meaning, topics, audio, external_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insF = $pdo->prepare("INSERT INTO files (id, name, type, kind, ext, size, sha256, by_sub, by_name, at, origin, deleted_at)
                           VALUES (?, ?, 'audio/mpeg', 'audio', 'mp3', ?, ?, 'u1', 'בודק', ?, ?, ?)");
    $seed = ['site' => [], 'tool' => [], 'none' => [], 'missing' => [], 'shared' => []];
    $pdo->beginTransaction();
    for ($i = 1; $i <= $n; $i++) {
        $audio = "https://$host/wp-content/uploads/kol/$i.mp3";
        $kind = 'site';
        if ($i % 750 === 0) { $audio = ''; $kind = 'none'; }                        // 2 מתוך 1,500 בלי הקלטה
        elseif ($i % 100 === 7) {                                                   // קובץ בכלי
            $audio = md5("file-$i");
            $kind = 'tool';
            if ($i === 107) $seed['missing'][] = $i;                                // מצביע על קובץ שלא קיים
            else $insF->execute([$audio, "הקלטה $i.mp3", 20000 + $i, hash('sha256', (string)$i), '2026-11-0' . ($i % 9 + 1) . 'T10:00:00Z',
                $i % 200 === 7 ? "https://$host/wp-content/uploads/kol/$i.mp3" : null, null]);
        } elseif ($i === 12) { $audio = "https://$host/wp-content/uploads/kol/11.mp3"; $seed['shared'] = [11, 12]; }   // שתי מילים, הקלטה אחת
        $seed[$kind][] = $i;
        $insW->execute([$i, "מילה $i", "<p>פירוש <b>מספר</b> $i</p><p>שורה שנייה</p>", json_encode([$topics[$i % 5]], JSON_UNESCAPED_UNICODE),
            $audio, $i % 3 ? '' : (string)(1000 + $i), '2026-11-01T00:00:00Z']);
    }
    $pdo->prepare("INSERT INTO draft (id, json, rev, updated_by, updated_at) VALUES (1, ?, 1, '', '2026-11-01T00:00:00Z')")->execute([json_encode(
        ['settings' => ['title' => 'קול מרוקו'], 'sections' => [['columns' => [['widgets' => [['type' => 'lexicon', 'props' =>
            ['hero_url' => "https://$host/wp-content/uploads/hero.jpg", 'hero_file' => '']]]]]]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $pdo->commit();
    return $seed;
}

function a1_assert(bool $ok, string $what): void
{
    if (!$ok) throw new RuntimeException($what);
}

$tests = [
    'A1: מספר השורות בספרייה = מספר המילים עם הקלטה (שלושת המצבים)' => function () {
        $seed = library_seed(1500);
        $lib = library_list();
        $with = array_filter($lib['rows'], fn($r) => $r['state'] !== 'none');
        a1_assert(count($with) === 1498, 'שורות הקלטה: ' . count($with) . ' במקום 1498');
        a1_assert($lib['counts']['audio'] === count($with), 'המונה אינו שווה למספר השורות');
        a1_assert($lib['counts']['site'] === count($seed['site']) && $lib['counts']['tool'] === count($seed['tool']), 'חלוקת באתר / בכלי שגויה');
        a1_assert($lib['counts']['site'] + $lib['counts']['tool'] === $lib['counts']['audio'], 'באתר + בכלי ≠ סך ההקלטות');
        a1_assert($lib['counts']['audio'] + $lib['counts']['none'] === $lib['counts']['words'] && $lib['counts']['words'] === 1500, 'הקלטות + בלי ≠ מילים');
    },
    'A1: המספרים שווים למה ש-📚 מילים מראה (שתי השאילתות), ולספירה ישירה במסד' => function () {
        library_seed(1500);
        $lib = library_list();
        $words = words_list()['words'];
        $kind = array_count_values(array_map(fn($w) => $w['audio_kind'], $words));
        a1_assert(count($words) === $lib['counts']['words'], 'מספר המילים שונה');
        a1_assert(count(array_filter($words, fn($w) => $w['audio'] !== '')) === $lib['counts']['audio'], '"עם הקלטה" שונה');
        a1_assert(($kind['site'] ?? 0) === $lib['counts']['site'], '"באתר" שונה');
        a1_assert(($kind['copy'] ?? 0) + ($kind['file'] ?? 0) + ($kind['missing'] ?? 0) === $lib['counts']['tool'], '"בכלי" שונה');
        a1_assert(($kind['missing'] ?? 0) === $lib['counts']['missing'], '"חסרה" שונה');
        a1_assert((int)db()->query("SELECT COUNT(*) FROM words WHERE audio != ''")->fetchColumn() === $lib['counts']['audio'], 'ספירה ישירה שונה');
        // אותו מקור השמעה בשני המסכים
        $src = array_column($words, 'audio_src', 'id');
        foreach ($lib['rows'] as $r) if ($r['state'] === 'site') a1_assert($r['src'] === $src[$r['id']], "מקור השמעה שונה במילה {$r['id']}");
    },
    'A1: "מילים בלי הקלטה" מחזיר בדיוק את המילים שאין להן' => function () {
        $seed = library_seed(1500);
        $none = array_column(array_filter(library_list()['rows'], fn($r) => $r['state'] === 'none'), 'id');
        sort($none);
        a1_assert($none === $seed['none'] && $none === [750, 1500], 'בלי הקלטה: ' . json_encode($none));
        $sql = array_map('intval', db()->query("SELECT id FROM words WHERE audio = '' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        a1_assert($none === $sql, 'שונה מהמסד');
    },
    'A1: אין הקלטה שהכלי יודע עליה ואינה מופיעה — גם קובץ חסר מופיע, מסומן' => function () {
        library_seed(1500);
        $rows = array_column(library_list()['rows'], null, 'id');
        a1_assert($rows[107]['state'] === 'tool' && $rows[107]['missing'] === true && $rows[107]['src'] === '', 'קובץ חסר לא סומן');
        a1_assert($rows[7]['state'] === 'tool' && !$rows[7]['missing'] && $rows[7]['file']['name'] === 'הקלטה 7.mp3' && $rows[7]['file']['size'] === 20007
            && str_starts_with($rows[7]['src'], 'api.php?action=media&id='), 'פרטי קובץ בכלי חסרים');
        a1_assert($rows[1]['state'] === 'site' && $rows[1]['src'] === 'https://maghreb.co.il/wp-content/uploads/kol/1.mp3', 'הקלטה באתר בלי הכתובת שלה');
        a1_assert($rows[1]['meaning'] === 'פירוש מספר 1 שורה שנייה', 'הפירוש לא הומר לטקסט: ' . $rows[1]['meaning']);
    },
    'A1: שתי מילים על אותה הקלטה — כל אחת רשומה בשורה של השנייה' => function () {
        library_seed(1500);
        $rows = array_column(library_list()['rows'], null, 'id');
        a1_assert(($rows[11]['shared'] ?? null) === [12] && ($rows[12]['shared'] ?? null) === [11], 'שיתוף הקלטה לא זוהה');
        a1_assert(!isset($rows[13]['shared']), 'שיתוף שגוי');
    },
    'A1: תמונת הרקע של המילון (כתובת באתר) מופיעה; כתובת מדומיין אחר — לא' => function () {
        library_seed(20);
        $img = library_list()['images'];
        a1_assert(count($img) === 1 && $img[0]['url'] === 'https://maghreb.co.il/wp-content/uploads/hero.jpg' && $img[0]['state'] === 'site', 'תמונת הרקע חסרה');
        db()->exec("UPDATE draft SET json = replace(json, 'https://maghreb.co.il/wp-content/uploads/hero.jpg', 'https://evil.example/x.jpg')");
        a1_assert(library_list()['images'] === [], 'תמונה מדומיין זר הוצגה');
        db()->exec('DELETE FROM draft');
        a1_assert(library_list()['images'] === [], 'בלי טיוטה צריך רשימה ריקה');
        a1_assert((int)db()->query('SELECT COUNT(*) FROM draft')->fetchColumn() === 0, 'הספרייה יצרה טיוטה');
    },
    'A1: הספרייה אינה משנה דבר במסד' => function () {
        library_seed(300);
        $dump = fn() => md5(json_encode([
            db()->query('SELECT * FROM words ORDER BY id')->fetchAll(), db()->query('SELECT * FROM files ORDER BY id')->fetchAll(),
            db()->query('SELECT * FROM draft')->fetchAll(), db()->query('SELECT * FROM topics ORDER BY slug')->fetchAll(),
            db()->query("SELECT * FROM settings WHERE k != 'media_secret' ORDER BY k")->fetchAll(), db()->query('SELECT COUNT(*) FROM audit')->fetchAll(),
            db()->query('PRAGMA user_version')->fetchAll()]));
        $before = $dump();
        library_list(); library_list();
        a1_assert($before === $dump(), 'המסד השתנה');
    },
    'A1: אין בקוד השרת של הספרייה פנייה לכתובת חיצונית או כתיבה' => function () {
        $code = (string)file_get_contents(__DIR__ . '/../lib/library.php');
        $code = (string)preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $code);                       // בלי הערות
        foreach (['curl_', 'file_get_contents', 'fopen', 'fsockopen', 'stream_', 'get_headers', 'copy(', 'file_put_contents', 'unlink',
                     'INSERT INTO', 'UPDATE ', 'DELETE FROM', 'setting_set', '->exec('] as $bad) {
            a1_assert(stripos($code, $bad) === false, "lib/library.php מכיל \"$bad\"");
        }
    },
];

if (!$direct) return $tests;

$fail = 0;
foreach ($tests as $name => $fn) {
    try { $fn(); echo "✅ $name\n"; }
    catch (Throwable $e) { $fail++; echo "❌ $name\n   {$e->getMessage()}\n"; }
}
echo $fail ? "\n$fail נכשלו\n" : "\nכל " . count($tests) . " הבדיקות עברו\n";
exit($fail ? 1 : 0);
