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
/** כותרת המשנה של מחירון — ברירת המחדל לכל קבוצה (נערכת לכל מחירון, 03-per-audience.md סעיף 1) */
const GP_SUBTITLES = ['il' => 'רכישת אחוזת קבר לתושבי ישראל', 'abroad' => 'רכישת קבר בחיים לתושבי חו"ל'];
const GP_KINDS = ['section', 'band', 'subsection', 'heading', 'item', 'fee', 'note'];
const GP_PRICED = ['item', 'fee'];
const GP_TOPS = ['section', 'band'];   // כותרת עליונה: נושאת אחוז בהעלאה
const GP_PRINT_MODES = ['one', 'split', 'both'];
/** מיגרציות שבונות טבלה מחדש — רצות עם foreign_keys כבוי (הנוהל של SQLite), ונבדקות ב-foreign_key_check לפני commit */
const GP_REBUILD_MIGRATIONS = [4];

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
        4 => function (PDO $pdo) {
            // פרוסה 03: מחירון שייך לקבוצה אחת — 03-per-audience.md סעיף 2. לא מוחקת דבר.
            gp_rebuild_editions($pdo);
            gp_settings_init($pdo);   // לפני נתוני ההתחלה: מהמחירון האחרון כפי שהבעלים השאיר אותו
            gp_migrate_abroad($pdo);
            gp_seed_one($pdo, gp_seed_file('abroad-2026-06-15.json'), 'seed-abroad-2026-06-15');
            gp_seed_one($pdo, gp_seed_file('israel-2026-06-15.json'), 'seed-il-2026-06-15');
        },
    ];
}

function gp_migrate(PDO $pdo): void
{
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    $all = gp_migrations();
    if ($v > 0 && $v < max(array_keys($all))) gp_backup($pdo, $v);
    foreach ($all as $n => $fn) {
        if ($n <= $v) continue;
        $rebuild = in_array($n, GP_REBUILD_MIGRATIONS, true);
        if ($rebuild) $pdo->exec('PRAGMA foreign_keys = OFF');   // לא משתנה בתוך טרנזקציה — לכן לפניה
        // IMMEDIATE: נועלים לכתיבה מההתחלה, ובודקים שוב את הגרסה — שתי בקשות ראשונות אחרי פריסה לא יריצו פעמיים
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < $n) {
                $fn($pdo);
                $bad = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
                if ($bad) throw new RuntimeException('מיגרציה ' . $n . ': הפניות שבורות — ' . json_encode($bad));
                $pdo->exec('PRAGMA user_version = ' . (int)$n);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            if ($rebuild) $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}

/** לפני מיגרציה על מסד קיים: עותק שלם לצידו (data/app.sqlite.v<גרסה>-<זמן>.bak). העתקה בלבד — לא מוחקים. */
function gp_backup(PDO $pdo, int $v): void
{
    $file = '';
    foreach ($pdo->query('PRAGMA database_list') as $d) if ($d['name'] === 'main') $file = (string)$d['file'];
    if ($file === '' || !is_file($file)) return;
    $to = $file . '.v' . $v . '-' . gmdate('Ymd\THis') . '.bak';
    if (is_file($to)) return;
    try {
        $pdo->exec('VACUUM INTO ' . $pdo->quote($to));   // עותק עקבי גם כשמישהו כותב
    } catch (Throwable) {
        if (is_file($to)) return;   // בקשה מקבילה כבר גיבתה
        if (!@copy($file, $to)) throw new RuntimeException('הגיבוי לפני המיגרציה נכשל');
    }
}

/** מזהה מחירון שלא היה בשימוש — גם לא של מחירון שנמחק, כדי שהיומן שלו לא יוצמד לחדש */
function gp_next_edition_id(PDO $pdo): int
{
    return 1 + (int)$pdo->query('SELECT MAX(m) FROM (SELECT MAX(id) AS m FROM editions UNION ALL SELECT MAX(edition_id) FROM audit)')->fetchColumn();
}

/**
 * editions נבנית מחדש: + audience, + subtitle, ו-UNIQUE(audience, effective_from) במקום UNIQUE(effective_from).
 * אותם id ו-pid — כך rows.edition_id והיומן נשארים מחוברים. כל מחירון קיים — ישראל.
 */
function gp_rebuild_editions(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(editions)')->fetchAll(), 'name');
    if (in_array('audience', $cols, true)) return;
    $before = $pdo->query('SELECT id, pid, effective_from, signatures, created_by, created_at, updated_at FROM editions ORDER BY id')->fetchAll();
    $pdo->exec("CREATE TABLE editions_new (
        id INTEGER PRIMARY KEY, pid TEXT NOT NULL UNIQUE, audience TEXT NOT NULL CHECK (audience IN ('il', 'abroad')),
        effective_from TEXT NOT NULL, subtitle TEXT NOT NULL DEFAULT '', signatures TEXT NOT NULL DEFAULT '[]',
        created_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (audience, effective_from))");
    $pdo->prepare("INSERT INTO editions_new (id, pid, audience, effective_from, subtitle, signatures, created_by, created_at, updated_at)
        SELECT id, pid, 'il', effective_from, ?, signatures, created_by, created_at, updated_at FROM editions")->execute([GP_SUBTITLES['il']]);
    $pdo->exec('DROP TABLE editions');
    $pdo->exec('ALTER TABLE editions_new RENAME TO editions');
    $after = $pdo->query('SELECT id, pid, effective_from, signatures, created_by, created_at, updated_at FROM editions ORDER BY id')->fetchAll();
    if ($after !== $before) throw new RuntimeException('מיגרציה 4: המחירונים לא הועתקו במלואם');
}

/**
 * הגדרות האפליקציה (key → JSON). החתימות — סט אחד לכל ההדפסות (הכרעת הבעלים 12:20); ערך התחלתי מהמחירון `il` האחרון
 * כפי שהוא במסד, כולל עריכות. editions.signatures נשאר ולא נמחק, אבל לא משמש.
 */
function gp_settings_init(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_by INTEGER, updated_at TEXT NOT NULL)');
    $sig = null;   // האחרון שיש בו שלוש חתימות
    foreach ($pdo->query("SELECT signatures FROM editions WHERE audience = 'il' ORDER BY effective_from DESC, id DESC")->fetchAll(PDO::FETCH_COLUMN) as $j) {
        $x = json_decode((string)$j, true);
        if (is_array($x) && count($x) === 3) { $sig = $x; break; }
    }
    $sig ??= gp_seed_file('israel-2026-06-15.json')['signatures'];
    $pdo->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?) ON CONFLICT (key) DO NOTHING')
        ->execute(['signatures', json_encode($sig, JSON_UNESCAPED_UNICODE), now_iso()]);
}

function gp_signatures(): array
{
    return json_decode((string)db()->query("SELECT value FROM settings WHERE key = 'signatures'")->fetchColumn(), true) ?: [];
}

/** מחירי חו"ל שהוקלדו במחירון ישראל → מחירון חו"ל באותו תאריך (המקורי לא משתנה). */
function gp_migrate_abroad(PDO $pdo): void
{
    $priced = "'" . implode("','", GP_PRICED) . "'";
    $eds = $pdo->query("SELECT e.* FROM editions e WHERE e.audience = 'il' AND EXISTS (SELECT 1 FROM rows r JOIN prices p ON p.row_id = r.id
        WHERE r.edition_id = e.id AND r.kind IN ($priced) AND p.audience = 'abroad' AND p.amount IS NOT NULL) ORDER BY e.effective_from")->fetchAll();
    $taken = $pdo->prepare("SELECT 1 FROM editions WHERE audience = 'abroad' AND effective_from = ?");
    $rows = $pdo->prepare("SELECT r.*, p.amount FROM rows r LEFT JOIN prices p ON p.row_id = r.id AND p.audience = 'abroad'
        WHERE r.edition_id = ? ORDER BY r.sort, r.id");
    foreach ($eds as $e) {
        $taken->execute([$e['effective_from']]);
        if ($taken->fetchColumn()) continue;
        $rows->execute([$e['id']]);
        $list = array_map(fn($r) => ['sort' => (int)$r['sort'], 'kind' => $r['kind'], 'indent' => (int)$r['indent'], 'label' => $r['label'],
            'price' => $r['amount'] === null ? null : (int)$r['amount']], $rows->fetchAll());
        $id = gp_insert_edition($pdo, 'abroad', $e['effective_from'], GP_SUBTITLES['abroad'], $e['signatures'], $list);
        gp_audit_pdo($pdo, 'migrate-abroad', $id, ['from' => $e['pid'], 'rows' => count($list)]);
    }
}

/** מחירון חדש עם השורות שלו; לכל שורה מחיר אחד (רשומה בקבוצה של המחירון). מחזיר את ה-id. */
function gp_insert_edition(PDO $pdo, string $aud, string $date, string $subtitle, string $sigsJson, array $rows): int
{
    $id = gp_next_edition_id($pdo);
    $now = now_iso();
    $pdo->prepare('INSERT INTO editions (id, pid, audience, effective_from, subtitle, signatures, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, gp_pid(), $aud, $date, $subtitle, $sigsJson, $now, $now]);
    $row = $pdo->prepare('INSERT INTO rows (edition_id, sort, kind, indent, label, pid) VALUES (?, ?, ?, ?, ?, ?)');
    $price = $pdo->prepare('INSERT INTO prices (row_id, audience, amount) VALUES (?, ?, ?)');
    foreach (array_values($rows) as $i => $r) {
        if (!in_array($r['kind'], GP_KINDS, true)) throw new RuntimeException('סוג שורה לא מוכר: ' . $r['kind']);
        $row->execute([$id, $r['sort'] ?? ($i + 1) * 10, $r['kind'], (int)$r['indent'], (string)$r['label'], gp_pid()]);
        $rid = (int)$pdo->lastInsertId();
        if (in_array($r['kind'], GP_PRICED, true) || ($r['price'] ?? null) !== null) {
            $price->execute([$rid, $aud, ($r['price'] ?? null) === null ? null : (int)$r['price']]);
        }
    }
    return $id;
}

/** נתוני התחלה של מחירון אחד — רק אם אין עדיין מחירון לאותה קבוצה באותו תאריך (מה שהבעלים יצר — גובר). */
function gp_seed_one(PDO $pdo, array $e, string $action): void
{
    $st = $pdo->prepare('SELECT 1 FROM editions WHERE audience = ? AND effective_from = ?');
    $st->execute([$e['audience'], $e['effective_from']]);
    if ($st->fetchColumn()) return;
    $id = gp_insert_edition($pdo, $e['audience'], $e['effective_from'], $e['subtitle'],
        json_encode($e['signatures'], JSON_UNESCAPED_UNICODE), $e['rows']);
    gp_audit_pdo($pdo, $action, $id, ['rows' => count($e['rows'])]);
}

/** נתוני ההתחלה של מחירון אחד — lib/seed/<file> (העתק של docs/spec/seed) */
function gp_seed_file(string $file): array
{
    return json_decode((string)file_get_contents(__DIR__ . '/seed/' . $file), true, 512, JSON_THROW_ON_ERROR)['edition'];
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
    // רץ במיגרציה 2, כש-editions עוד במבנה של 01 (בלי audience) — מיגרציה 4 משלימה
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
    gp_audit_pdo(db(), $action, $editionId, $details, $userId);
}

function gp_audit_pdo(PDO $pdo, string $action, ?int $editionId = null, array $details = [], ?int $userId = null): void
{
    $pdo->prepare('INSERT INTO audit (at, user_id, action, edition_id, details) VALUES (?, ?, ?, ?, ?)')
        ->execute([now_iso(), $userId, $action, $editionId, json_encode($details, JSON_UNESCAPED_UNICODE)]);
}
