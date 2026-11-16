<?php
/**
 * המסד (SQLite אחד ב-data/app.sqlite), המיגרציות ונתוני ההתחלה — docs/spec/00-overview.md סעיף 2.
 * מיגרציה לעולם לא מוחקת נתונים. הגרסה נשמרת ב-PRAGMA user_version.
 */

declare(strict_types=1);

final class ApiError extends RuntimeException
{
    /** $extra — שדות נוספים בתשובה, למשל {"need_confirm": true} */
    public function __construct(string $message, int $code = 400, public readonly array $extra = [])
    {
        parent::__construct($message, $code);
    }
}

const GP_AUDIENCES = ['il' => 'תושבי ישראל', 'abroad' => 'תושבי חו"ל'];
const GP_KINDS = ['section', 'subsection', 'heading', 'item', 'fee', 'note'];
const GP_PRICED = ['item', 'fee'];
const GP_PRINT_MODES = ['il', 'abroad', 'split', 'both'];

function gp_data_dir(): string
{
    return getenv('GP_DATA_DIR') ?: dirname(__DIR__) . '/data';   // בבדיקות — תיקייה זמנית
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = gp_data_dir();
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $pdo = new PDO('sqlite:' . $dir . '/app.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000');
    gp_migrate($pdo);
    return $pdo;
}

/** המיגרציות — לפי הסדר. מוסיפים בסוף בלבד, ואף אחת לא מוחקת. */
function gp_migrations(): array
{
    return [
        1 => function (PDO $pdo) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY, sub TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT '', email TEXT,
                    is_dev INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, last_seen_at TEXT);
                CREATE TABLE IF NOT EXISTS editions (
                    id INTEGER PRIMARY KEY, pid TEXT NOT NULL UNIQUE, effective_from TEXT NOT NULL UNIQUE,
                    signatures TEXT NOT NULL DEFAULT '[]', created_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS rows (
                    id INTEGER PRIMARY KEY, edition_id INTEGER NOT NULL REFERENCES editions(id),
                    sort INTEGER NOT NULL, kind TEXT NOT NULL, indent INTEGER NOT NULL DEFAULT 0, label TEXT NOT NULL DEFAULT '');
                CREATE INDEX IF NOT EXISTS rows_edition ON rows(edition_id, sort);
                CREATE TABLE IF NOT EXISTS prices (
                    row_id INTEGER NOT NULL REFERENCES rows(id), audience TEXT NOT NULL, amount INTEGER,
                    PRIMARY KEY (row_id, audience));
                CREATE TABLE IF NOT EXISTS audit (
                    id INTEGER PRIMARY KEY, at TEXT NOT NULL, user_id INTEGER, action TEXT NOT NULL,
                    edition_id INTEGER, details TEXT NOT NULL DEFAULT '{}');
            ");
        },
        2 => function (PDO $pdo) {
            // נתוני ההתחלה — רק כשאין מחירונים (פריסה חוזרת, או מסד שכבר נערך, לא נוגעת)
            if ((int)$pdo->query('SELECT COUNT(*) FROM editions')->fetchColumn() === 0) gp_seed($pdo);
        },
        3 => function (PDO $pdo) {
            // פרוסה 02: לכל שורה pid משלה (ל-API). מוסיפה עמודה וממלאת — לא מוחקת ולא טוענת מחדש.
            $cols = array_column($pdo->query('PRAGMA table_info(rows)')->fetchAll(), 'name');
            if (!in_array('pid', $cols, true)) $pdo->exec('ALTER TABLE rows ADD COLUMN pid TEXT');
            $up = $pdo->prepare('UPDATE rows SET pid = ? WHERE id = ?');
            foreach ($pdo->query('SELECT id FROM rows WHERE pid IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $id) $up->execute([gp_pid(), $id]);
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS rows_pid ON rows(pid);
                        CREATE INDEX IF NOT EXISTS audit_edition ON audit(edition_id)');
        },
    ];
}

function gp_migrate(PDO $pdo): void
{
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    foreach (gp_migrations() as $n => $fn) {
        if ($n <= $v) continue;
        $pdo->beginTransaction();
        try {
            $fn($pdo);
            $pdo->exec('PRAGMA user_version = ' . (int)$n);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

function gp_pid(): string
{
    $abc = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $s = '';
    for ($i = 0; $i < 12; $i++) $s .= $abc[random_int(0, 35)];
    return $s;
}

/** טוען את lib/seed/editions.json (העתק של docs/spec/seed). */
function gp_seed(PDO $pdo): void
{
    $seed = json_decode((string)file_get_contents(__DIR__ . '/seed/editions.json'), true, 512, JSON_THROW_ON_ERROR);
    $now = now_iso();
    $ed = $pdo->prepare('INSERT INTO editions (pid, effective_from, signatures, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
    $row = $pdo->prepare('INSERT INTO rows (edition_id, sort, kind, indent, label) VALUES (?, ?, ?, ?, ?)');
    $price = $pdo->prepare('INSERT INTO prices (row_id, audience, amount) VALUES (?, ?, ?)');
    foreach ($seed['editions'] as $e) {
        $ed->execute([gp_pid(), $e['effective_from'], json_encode($e['signatures'], JSON_UNESCAPED_UNICODE), $now, $now]);
        $eid = (int)$pdo->lastInsertId();
        foreach ($e['rows'] as $i => $r) {
            if (!in_array($r['kind'], GP_KINDS, true)) throw new RuntimeException('סוג שורה לא מוכר: ' . $r['kind']);
            $row->execute([$eid, ($i + 1) * 10, $r['kind'], (int)$r['indent'], (string)$r['label']]);
            $rid = (int)$pdo->lastInsertId();
            if (!in_array($r['kind'], GP_PRICED, true)) continue;
            foreach (array_keys(GP_AUDIENCES) as $a) {
                $amt = $r['prices'][$a] ?? null;
                $price->execute([$rid, $a, $amt === null ? null : (int)$amt]);
            }
        }
    }
}

function gp_audit(?int $userId, string $action, ?int $editionId = null, array $details = []): void
{
    db()->prepare('INSERT INTO audit (at, user_id, action, edition_id, details) VALUES (?, ?, ?, ?, ?)')
        ->execute([now_iso(), $userId, $action, $editionId, json_encode($details, JSON_UNESCAPED_UNICODE)]);
}
