<?php
/**
 * גיבויים ושחזור (אפיון 10 §4, §19).
 *
 *   הגיבוי      — קובץ zip אחד ב-data/backups/ (חסומה מהדפדפן: data/.htaccess, ועוד .htaccess משלה; מוחרגת מהפריסה עם כל
 *                 data/*; לא בגיט — sforno/.gitignore). בתוכו: sforno.sqlite (VACUUM INTO — עותק עקבי של המסד בזמן ריצה),
 *                 המפתחות (secret.key — בלעדיו ההערות ושמות התפילה לא מתפענחים; vapid.json — Push; woo.json — החנות;
 *                 cron.token; db.json אם יש), data/sources/ (קובצי הייבוא) ו-data/_dev/ (קבצים של DevLoop), ו-manifest.json:
 *                 ספירות לפי טבלה ו-sha256 לכל קובץ. הרשאות 0600.
 *   שימור       — 30 יום (SF_BACKUP_KEEP). הגיבוי האחרון לא נמחק לעולם, גם אם ישן.
 *   בדיקת שחזור — sf_backup_verify: ה-sha256 של הקובץ ושל כל רכיב, פתיחת המסד המשוחזר בתיקייה זמנית, integrity_check,
 *                 ספירות זהות למה שנרשם בגיבוי, ופענוח פריט מוצפן במפתח שבגיבוי. פעם בחודש (ops.restore_check), ובכל
 *                 רגע מהמסך או מ-CLI (tools/backup.php verify).
 *   עותק בחוץ   — ⏳ 📦 מדיניות גיבוי: עד הכרעה, "הורדת הגיבוי האחרון" למפתח (dev-backup-download) — **מוצפן בסיסמה** — ומטלה
 *                 חודשית לבעלים. מוצפן ← tools/backup.php import (או decrypt).
 * שחזור ידני — dev/restore.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/health.php';

const SF_BACKUP_KEEP = 30 * 86400;
const SF_BACKUP_VERIFY_EVERY = 30 * 86400;
const SF_BACKUP_FILES = ['secret.key', 'vapid.json', 'woo.json', 'cron.token', 'db.json'];
const SF_BACKUP_DIRS = ['sources', '_dev'];
const SF_BACKUP_NAME = '/^sforno-\d{8}-\d{6}-[a-z0-9]{6}\.zip\z/';
const SF_BACKUP_ENTRY = '~^(sforno\.sqlite|manifest\.json|[A-Za-z0-9._-]+\.(key|json|token)|(sources|_dev)(/[A-Za-z0-9._-]+)+)\z~';
const SF_BACKUP_MAX_FILE = 300 * 1048576;   // קובץ בודד מעבר לזה (וידאו שהועלה?) — לא נכנס, ונרשם ב-manifest

function sf_backup_dir(): string
{
    $d = sf_data_dir() . '/backups';
    if (!is_dir($d) && !@mkdir($d, 0700, true)) throw new SfError('storage');
    if (!is_file("$d/.htaccess")) {
        @file_put_contents("$d/.htaccess", "# הגיבויים — לעולם לא מהדפדפן.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $d;
}

/** ספירת השורות בכל טבלה. */
function sf_backup_counts(PDO $db): array
{
    $out = [];
    foreach ($db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $out[$t] = (int)$db->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $t) . '"')->fetchColumn();
    }
    return $out;
}

function sf_backup_open(string $file): PDO
{
    $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout = 5000');
    return $db;
}

/** הקבצים שמחוץ למסד: [שם ב-zip => נתיב]. בלי קישורים סימבוליים, בלי -wal/-shm ובלי היומן הטכני (log.sqlite, 30 יום). */
function sf_backup_sources(array &$skipped): array
{
    $root = sf_data_dir();
    $out = [];
    foreach (SF_BACKUP_FILES as $f) if (is_file("$root/$f") && !is_link("$root/$f")) $out[$f] = "$root/$f";
    foreach (SF_BACKUP_DIRS as $d) {
        if (!is_dir("$root/$d") || is_link("$root/$d")) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isLink() || !$file->isFile()) continue;
            $rel = $d . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen("$root/$d/")));
            if (preg_match('/(-wal|-shm|\.lock)\z|(^|\/)log\.sqlite/', $rel)) continue;
            if (!preg_match(SF_BACKUP_ENTRY, $rel)) { $skipped[] = $rel; continue; }
            if ($file->getSize() > SF_BACKUP_MAX_FILE) { $skipped[] = $rel; continue; }
            $out[$rel] = $file->getPathname();
        }
    }
    return $out;
}

/** גיבוי עכשיו. $kind: daily · manual · pre-restore. מחזיר את השורה (בלי נתיב). */
function sf_backup_make(string $kind = 'daily'): array
{
    $db = sf_db();
    if (sf_driver($db) !== 'sqlite') sf_fail('state', 'הגיבוי האוטומטי — למסד SQLite. ב-MySQL: גיבוי של cPanel');
    if (!class_exists('ZipArchive')) sf_fail('state', 'חסרה ההרחבה zip בשרת');
    $dir = sf_backup_dir();
    $pid = sf_pid();
    $name = 'sforno-' . gmdate('Ymd-His', sf_time()) . '-' . substr($pid, 0, 6) . '.zip';
    $tmpDb = "$dir/.tmp-$pid.sqlite";
    $tmpZip = "$dir/.tmp-$pid.zip";
    try {
        $db->exec('VACUUM INTO ' . $db->quote($tmpDb));
        $counts = sf_backup_counts(sf_backup_open($tmpDb));
        $skipped = [];
        $files = ['sforno.sqlite' => $tmpDb] + sf_backup_sources($skipped);
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('zip לא נפתח');
        $sums = [];
        foreach ($files as $entry => $path) {
            $sums[$entry] = hash_file('sha256', $path);
            $zip->addFile($path, $entry);
        }
        $manifest = ['app' => 'sforno', 'version' => sf_version(), 'schema' => sf_schema_version($db), 'created_at' => sf_now(), 'kind' => $kind,
            'counts' => $counts, 'files' => $sums, 'skipped' => $skipped];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (!$zip->close()) throw new RuntimeException('zip לא נסגר');
        @chmod($tmpZip, 0600);
        if (!@rename($tmpZip, "$dir/$name")) throw new RuntimeException('השמירה נכשלה');
    } catch (Throwable $e) {
        @unlink($tmpZip);
        if ($e instanceof SfError) throw $e;
        throw new SfError('storage', 'הגיבוי נכשל: ' . get_class($e));
    } finally {
        @unlink($tmpDb);
    }
    $row = ['pid' => $pid, 'file' => $name, 'kind' => $kind, 'bytes' => (int)filesize("$dir/$name"), 'sha256' => hash_file('sha256', "$dir/$name"),
        'counts' => json_encode($counts), 'created_at' => sf_now()];
    sf_tx(function () use ($row, $kind) {
        sf_insert('backups', $row);
        sf_audit('backup.create', 'backup', $row['pid'], ['kind' => $kind, 'bytes' => $row['bytes']], actor: $kind === 'daily' ? null : false);
    });
    if ($kind !== 'pre-restore') sf_backup_prune();   // לפני שחזור — לא מוחקים דבר (הגיבוי שמשחזרים עלול להיות ישן)
    return sf_backup_out(sf_row('SELECT * FROM backups WHERE pid = ?', [$pid]));
}

/** שימור: גיבוי מעל 30 יום נמחק — חוץ מהאחרון. גם שאריות של גיבוי שנכשל באמצע. מחזיר כמה נמחקו. */
function sf_backup_prune(): int
{
    $dir = sf_backup_dir();
    $latest = (int)sf_val('SELECT MAX(id) FROM backups WHERE deleted_at IS NULL');
    $n = 0;
    foreach (sf_rows('SELECT * FROM backups WHERE deleted_at IS NULL AND created_at < ? AND id <> ?', [sf_iso(sf_time() - SF_BACKUP_KEEP), $latest]) as $b) {
        if (preg_match(SF_BACKUP_NAME, $b['file'])) @unlink("$dir/{$b['file']}");
        sf_exec('UPDATE backups SET deleted_at = ? WHERE id = ?', [sf_now(), $b['id']]);
        $n++;
    }
    foreach (glob("$dir/.tmp-*") ?: [] as $f) if (filemtime($f) < time() - 86400) @unlink($f);
    foreach (glob("$dir/.verify-*", GLOB_ONLYDIR) ?: [] as $d) if (filemtime($d) < time() - 86400) sf_backup_rmdir($d);
    return $n;
}

function sf_backup_rmdir(string $d): void
{
    if (!is_dir($d) || is_link($d)) return;
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) is_dir("$d/$f") && !is_link("$d/$f") ? sf_backup_rmdir("$d/$f") : @unlink("$d/$f");
    @rmdir($d);
}

function sf_backup_out(?array $b): ?array
{
    if (!$b) return null;
    return ['pid' => $b['pid'], 'file' => $b['file'], 'kind' => $b['kind'], 'bytes' => (int)$b['bytes'], 'sha256' => $b['sha256'], 'created_at' => $b['created_at'],
        'counts' => json_decode((string)$b['counts'], true), 'verified_at' => $b['verified_at'], 'verify_ok' => $b['verify_ok'] === null ? null : (bool)$b['verify_ok'],
        'verify_note' => $b['verify_note'], 'deleted_at' => $b['deleted_at']];
}

/** השורה והנתיב של גיבוי (האחרון, או לפי pid). */
function sf_backup_find(?string $pid = null): array
{
    $b = $pid !== null ? (sf_is_pid($pid) ? sf_row('SELECT * FROM backups WHERE pid = ? AND deleted_at IS NULL', [$pid]) : null)
        : sf_row('SELECT * FROM backups WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
    if (!$b || !preg_match(SF_BACKUP_NAME, $b['file'])) sf_fail('not_found', 'אין גיבוי');
    $path = sf_backup_dir() . '/' . $b['file'];
    if (!is_file($path)) sf_fail('not_found', 'קובץ הגיבוי חסר בשרת');
    return [$b, $path];
}

/** פותח את ה-zip ובודק את ה-manifest ואת ה-sha256 של כל רכיב. מחזיר [ZipArchive, manifest]. */
function sf_backup_zip(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('קובץ הגיבוי פגום (zip)');
    $m = json_decode((string)$zip->getFromName('manifest.json'), true);
    if (!is_array($m) || ($m['app'] ?? '') !== 'sforno' || !is_array($m['files'] ?? null) || !is_array($m['counts'] ?? null)) throw new RuntimeException('manifest.json חסר או פגום');
    foreach ($m['files'] as $entry => $sum) {
        if (!is_string($entry) || !preg_match(SF_BACKUP_ENTRY, $entry) || str_contains($entry, '..')) throw new RuntimeException('שם רכיב לא תקין');
        $st = $zip->getStream($entry);
        if (!$st) throw new RuntimeException("חסר בגיבוי: $entry");
        $ctx = hash_init('sha256');
        hash_update_stream($ctx, $st);
        fclose($st);
        if (!hash_equals((string)$sum, hash_final($ctx))) throw new RuntimeException("sha256 לא תואם: $entry");
    }
    return [$zip, $m];
}

/** מחלץ את כל הרכיבים (לפי ה-manifest בלבד) לתיקייה. */
function sf_backup_extract(ZipArchive $zip, array $m, string $to): void
{
    if (!is_dir($to) && !@mkdir($to, 0700, true)) throw new RuntimeException('אי אפשר ליצור את תיקיית היעד');
    foreach (array_keys($m['files']) as $entry) {
        $dest = $to . '/' . $entry;
        if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0700, true)) throw new RuntimeException('אי אפשר ליצור תיקייה');
        $in = $zip->getStream($entry);
        $out = @fopen($dest, 'xb');
        if (!$in || !$out) throw new RuntimeException("החילוץ נכשל: $entry");
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        @chmod($dest, 0600);
    }
}

/**
 * בדיקת שחזור (§21.16): משחזר את הגיבוי (האחרון) למסד זמני ובודק — sha256, integrity_check, ספירות זהות, ופענוח במפתח שבגיבוי.
 * $keep — תיקייה שאליה לחלץ ולהשאיר (CLI: restore-to); אחרת תיקייה זמנית שנמחקת. התוצאה נרשמת בשורת הגיבוי.
 */
function sf_backup_verify(?string $pid = null, ?string $keep = null): array
{
    [$b, $path] = sf_backup_find($pid);
    $checks = [];
    $ok = true;
    $tmp = $keep ?? sf_backup_dir() . '/.verify-' . bin2hex(random_bytes(6));
    $counts = null;
    try {
        if (!hash_equals($b['sha256'], hash_file('sha256', $path))) throw new RuntimeException('ה-sha256 של הקובץ השתנה מאז הגיבוי');
        $checks[] = ['ok' => true, 'text' => 'sha256 של הקובץ תואם'];
        [$zip, $m] = sf_backup_zip($path);
        $checks[] = ['ok' => true, 'text' => count($m['files']) . ' רכיבים, sha256 תואם לכל אחד'];
        sf_backup_extract($zip, $m, $tmp);
        $zip->close();
        $rdb = sf_backup_open("$tmp/sforno.sqlite");
        $ic = (string)$rdb->query('PRAGMA integrity_check')->fetchColumn();
        if ($ic !== 'ok') throw new RuntimeException("integrity_check: $ic");
        $checks[] = ['ok' => true, 'text' => 'המסד המשוחזר נפתח · integrity_check תקין'];
        $counts = sf_backup_counts($rdb);
        $want = json_decode((string)$b['counts'], true) ?: [];
        if ($counts !== $m['counts'] || $counts !== $want) {
            $diff = array_values(array_filter(array_unique(array_merge(array_keys($want), array_keys($counts))), fn($t) => ($want[$t] ?? null) !== ($counts[$t] ?? null)));
            throw new RuntimeException('הספירות לא זהות: ' . implode(', ', array_slice($diff, 0, 8)));
        }
        $checks[] = ['ok' => true, 'text' => 'הספירות זהות ב-' . count($counts) . ' טבלאות (' . array_sum($counts) . ' שורות)'];
        // המפתח: בלעדיו ההערות ושמות התפילה לא מתפענחים
        $key = is_file("$tmp/secret.key") ? (string)file_get_contents("$tmp/secret.key") : '';
        $sealed = sf_reader_sealed_sample($rdb) ?? false;
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            if ($sealed !== false) throw new RuntimeException('secret.key חסר בגיבוי — ההערות לא יתפענחו');
            $checks[] = ['ok' => true, 'text' => 'אין עדיין secret.key ואין מידע מוצפן'];
        } elseif ($sealed !== false) {
            $raw = base64_decode(substr((string)$sealed, 3), true);
            $plain = $raw === false ? false : sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
            if ($plain === false) throw new RuntimeException('secret.key שבגיבוי לא מפענח את המידע המוצפן');
            $checks[] = ['ok' => true, 'text' => 'secret.key שבגיבוי מפענח פריט מוצפן'];
        } else {
            $checks[] = ['ok' => true, 'text' => 'secret.key בגיבוי (אין עדיין מידע מוצפן לבדוק)'];
        }
        $rdb = null;
    } catch (Throwable $e) {
        $ok = false;
        $checks[] = ['ok' => false, 'text' => $e instanceof SfError ? $e->getMessage() : mb_substr($e->getMessage(), 0, 200)];
    } finally {
        if ($keep === null) sf_backup_rmdir($tmp);
    }
    $note = implode(' · ', array_column($checks, 'text'));
    sf_exec('UPDATE backups SET verified_at = ?, verify_ok = ?, verify_note = ? WHERE id = ?', [sf_now(), $ok ? 1 : 0, mb_substr($note, 0, 1000), $b['id']]);
    sf_audit('backup.verify', 'backup', $b['pid'], ['ok' => $ok], actor: null, kind: 'system');
    return ['passed' => $ok, 'backup' => sf_backup_out(sf_row('SELECT * FROM backups WHERE id = ?', [$b['id']])), 'checks' => $checks, 'counts' => $counts];
}

// ── מצב תחזוקה (בזמן שחזור) ──────────────────────────────

/** data/maintenance.json קיים = שחזור רץ: api.php עונה 503 (upgrading) ו-cron.php לא מריץ משימות. */
function sf_maint_file(): string
{
    return sf_data_dir() . '/maintenance.json';
}

function sf_in_maintenance(): bool
{
    return is_file(sf_maint_file());
}

function sf_backup_rename(string $from, string $to): bool
{
    if (isset($GLOBALS['sf_rename'])) return (bool)($GLOBALS['sf_rename'])($from, $to);   // בבדיקות — כשל באמצע
    return @rename($from, $to);
}

/**
 * שחזור אמיתי (CLI בלבד — tools/backup.php apply, לפי dev/restore.md). **הכול או כלום:**
 *   1. מצב תחזוקה (api.php — 503, cron — לא רץ), והמתנה קצרה לבקשות שבאמצע
 *   2. גיבוי בטיחות של המצב הנוכחי; חילוץ ובדיקה של הגיבוי לתיקייה זמנית
 *   3. החלפה: כל rename נבדק; כשל ← כל מה שכבר הוזז חוזר למקומו (בסדר הפוך), וזריקה. שום דבר לא נמחק:
 *      מה שהוחלף נשמר ב-data/.replaced-<זמן>/
 *   4. יציאה ממצב תחזוקה — תמיד (גם בכשל)
 */
function sf_backup_apply(?string $pid = null): array
{
    if (PHP_SAPI !== 'cli') throw new LogicException('רק מ-CLI');
    [$b] = sf_backup_find($pid);
    $root = sf_data_dir();
    if (sf_in_maintenance()) throw new RuntimeException('שחזור אחר רץ (data/maintenance.json). אם לא — למחוק את הקובץ ולנסות שוב');
    if (@file_put_contents(sf_maint_file(), json_encode(['since' => gmdate('c'), 'backup' => $b['pid']])) === false) throw new RuntimeException('אי אפשר לכתוב ל-data/');
    $stamp = gmdate('Ymd-His');
    $stage = "$root/.restore-$stamp";
    $old = "$root/.replaced-$stamp";
    $done = [];   // [from, to] — ההזזות שבוצעו, לביטול
    try {
        $wait = $GLOBALS['sf_apply_wait'] ?? 3;
        if ($wait > 0) sleep($wait);   // בקשות שהתחילו לפני מצב התחזוקה — מסיימות
        $safety = sf_backup_make('pre-restore');
        $v = sf_backup_verify($b['pid'], $stage);
        if (!$v['passed']) throw new RuntimeException('בדיקת הגיבוי נכשלה — לא שוחזר דבר');
        @unlink("$stage/manifest.json");
        $GLOBALS['sf_pdo'] = null;
        unset($GLOBALS['sf_pdo']);
        if (!@mkdir($old, 0700)) throw new RuntimeException('אי אפשר ליצור את ' . basename($old));
        $move = function (string $from, string $to) use (&$done): void {
            if (!sf_backup_rename($from, $to)) throw new RuntimeException('ההזזה נכשלה: ' . basename($from));
            $done[] = [$from, $to];
        };
        $names = array_values(array_diff(scandir($stage) ?: [], ['.', '..']));
        foreach ($names as $name) {
            $live = $name === 'sforno.sqlite' ? sf_db_path() : "$root/$name";
            if ($name === 'sforno.sqlite') foreach (['-wal', '-shm'] as $sfx) if (is_file($live . $sfx)) $move($live . $sfx, "$old/$name$sfx");
            if (file_exists($live)) $move($live, "$old/$name");
            $move("$stage/$name", $live);
        }
        @rmdir($stage);
        return ['restored' => $b['file'], 'safety_backup' => $safety['file'], 'replaced_dir' => basename($old), 'items' => $names];
    } catch (Throwable $e) {
        $GLOBALS['sf_pdo'] = null;
        unset($GLOBALS['sf_pdo']);
        $lost = [];
        foreach (array_reverse($done) as [$from, $to]) {
            if (!@rename($to, $from)) $lost[] = basename($from);   // הביטול — rename אמיתי, בלי ה-hook של הבדיקות
        }
        if (is_dir($stage)) sf_backup_rmdir($stage);
        @rmdir($old);   // רק אם ריקה
        throw new RuntimeException($lost
            ? 'השחזור נכשל באמצע, וגם ההחזרה לא הושלמה: ' . implode(', ', $lost) . ' — הקבצים ב-' . basename($old) . '/. לפי dev/restore.md, "כשל"'
            : 'השחזור נכשל — הוחזר המצב הקודם, לא שונה דבר (' . $e->getMessage() . ')', 0, $e);
    } finally {
        @unlink(sf_maint_file());
    }
}

// ── הגיבוי שיוצא מהשרת: מוצפן בסיסמה ──────────────────────

/**
 * הגיבוי שיורד מהשרת מכיל את secret.key ליד המסד — ולכן יוצא רק מוצפן: Argon2id (sodium_crypto_pwhash) מהסיסמה, ו-XChaCha20-
 * Poly1305 secretstream בחלקים של 64KB. **הסיסמה לא נשמרת בשרת** ולא ביומן. בלעדיה — אין שחזור מהעותק הזה.
 * המבנה: MAGIC · ops (4) · mem (4) · salt (16) · header (24) · [אורך (4) · חלק]… — החלק האחרון עם TAG_FINAL (קובץ קטוע — נדחה).
 */
const SF_BACKUP_ENC_MAGIC = "SFORNO-BACKUP-1\n";
const SF_BACKUP_ENC_CHUNK = 65536;
const SF_BACKUP_PW_MIN = 12;

function sf_backup_pw(mixed $pw): string
{
    if (!is_string($pw) || mb_strlen($pw) < SF_BACKUP_PW_MIN || strlen($pw) > 200) sf_fail('bad_input', 'סיסמה של ' . SF_BACKUP_PW_MIN . ' תווים לפחות');
    return $pw;
}

/** מצפין קובץ אל זרם פתוח. */
function sf_backup_encrypt(string $inPath, $out, string $pw): void
{
    $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
    $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;   // 64MB — בתוך memory_limit של שרת משותף
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $pw, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
    sodium_memzero($key);
    fwrite($out, SF_BACKUP_ENC_MAGIC . pack('NN', $ops, $mem) . $salt . $header);
    $in = fopen($inPath, 'rb') ?: throw new RuntimeException('הגיבוי לא נפתח');
    $chunk = (string)fread($in, SF_BACKUP_ENC_CHUNK);
    while (true) {
        $next = feof($in) ? '' : (string)fread($in, SF_BACKUP_ENC_CHUNK);
        $last = $next === '' && feof($in);
        $c = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
        fwrite($out, pack('N', strlen($c)) . $c);
        if ($last) break;
        $chunk = $next;
    }
    fclose($in);
}

/** מפענח קובץ מוצפן ל-$outPath (חדש). סיסמה שגויה / קובץ פגום או קטוע — חריגה, ו-$outPath לא נשאר. */
function sf_backup_decrypt(string $inPath, string $outPath, string $pw): void
{
    $in = @fopen($inPath, 'rb') ?: throw new RuntimeException('הקובץ לא נפתח');
    $out = null;
    try {
        $hlen = strlen(SF_BACKUP_ENC_MAGIC) + 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $h = (string)fread($in, $hlen);
        if (strlen($h) !== $hlen || !str_starts_with($h, SF_BACKUP_ENC_MAGIC)) throw new RuntimeException('זה לא גיבוי מוצפן של ספורנו');
        $p = strlen(SF_BACKUP_ENC_MAGIC);
        ['o' => $ops, 'm' => $mem] = unpack('No/Nm', substr($h, $p, 8));
        if ($ops < 1 || $ops > 20 || $mem < 8192 || $mem > (1 << 30)) throw new RuntimeException('כותרת לא תקינה');
        $salt = substr($h, $p + 8, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $header = substr($h, $p + 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $pw, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        sodium_memzero($key);
        $out = @fopen($outPath, 'xb') ?: throw new RuntimeException('אי אפשר לכתוב את ' . basename($outPath));
        $final = false;
        while (!$final) {
            $l = fread($in, 4);
            if ($l === false || strlen($l) !== 4) throw new RuntimeException('הקובץ קטוע');
            $n = unpack('N', $l)[1];
            if ($n < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $n > SF_BACKUP_ENC_CHUNK + 64) throw new RuntimeException('הקובץ פגום');
            $c = (string)fread($in, $n);
            $r = strlen($c) === $n ? sodium_crypto_secretstream_xchacha20poly1305_pull($state, $c) : false;
            if ($r === false) throw new RuntimeException('סיסמה שגויה, או שהקובץ פגום');
            fwrite($out, $r[0]);
            $final = $r[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        if (fread($in, 1) !== '') throw new RuntimeException('יש נתונים אחרי סוף הגיבוי');
        fclose($out);
        $out = null;
        @chmod($outPath, 0600);
    } catch (Throwable $e) {
        if ($out) fclose($out);
        @unlink($outPath);
        throw $e;
    } finally {
        fclose($in);
    }
}

/**
 * import (CLI): גיבוי מוצפן שהורד בעבר → מפוענח (בסיסמה), נבדק (manifest ו-sha256) ונרשם כגיבוי בשרת, ואז אפשר verify / apply.
 * למקרה שהשרת אבד והגיבויים שבו איתו.
 */
function sf_backup_import(string $file, string $pw): array
{
    $dir = sf_backup_dir();
    $pid = sf_pid();
    $tmp = "$dir/.tmp-$pid.zip";
    sf_backup_decrypt($file, $tmp, $pw);
    try {
        [$zip, $m] = sf_backup_zip($tmp);
        $zip->close();
    } catch (Throwable $e) {
        @unlink($tmp);
        throw $e;
    }
    $name = 'sforno-' . gmdate('Ymd-His', sf_time()) . '-' . substr($pid, 0, 6) . '.zip';
    if (!@rename($tmp, "$dir/$name")) { @unlink($tmp); throw new RuntimeException('השמירה נכשלה'); }
    sf_tx(function () use ($pid, $name, $dir, $m) {
        sf_insert('backups', ['pid' => $pid, 'file' => $name, 'kind' => 'import', 'bytes' => (int)filesize("$dir/$name"), 'sha256' => hash_file('sha256', "$dir/$name"),
            'counts' => json_encode($m['counts']), 'created_at' => sf_now()]);
        sf_audit('backup.import', 'backup', $pid, ['from' => (string)($m['created_at'] ?? '')], actor: null, kind: 'system');
    });
    return sf_backup_out(sf_row('SELECT * FROM backups WHERE pid = ?', [$pid]));
}

// ── מסך המפתח ───────────────────────────────────────────────

function sf_dev_backups(): array
{
    $dir = sf_backup_dir();
    return ['backups' => array_map('sf_backup_out', sf_rows('SELECT * FROM backups WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 40')),
        'keep_days' => SF_BACKUP_KEEP / 86400, 'free_mb' => ($f = @disk_free_space($dir)) === false ? null : (int)round($f / 1048576),
        'last_verify' => sf_backup_out(sf_row('SELECT * FROM backups WHERE verified_at IS NOT NULL ORDER BY verified_at DESC LIMIT 1')),
        'includes' => array_merge(['sforno.sqlite'], SF_BACKUP_FILES, array_map(fn($d) => "$d/", SF_BACKUP_DIRS)),
        'support' => function_exists('sf_ops_support') ? sf_ops_support() : null];
}

function sf_dev_backup_now(): array
{
    return ['backup' => sf_backup_make('manual')];
}

function sf_dev_backup_verify(array $in): array
{
    return sf_backup_verify(is_string($in['backup'] ?? null) ? $in['backup'] : null);
}

/**
 * dev-backup-download (dev, POST {backup?, password}): הגיבוי **מוצפן בסיסמה** (sf_backup_encrypt), כקובץ להורדה. הסיסמה לא נשמרת
 * ולא נרשמת; ההורדה נרשמת ביומן הפעולות. בבדיקות ($GLOBALS['sf_send_out']) — לקובץ זמני.
 */
function sf_dev_backup_download(array $in): array
{
    $pw = sf_backup_pw($in['password'] ?? null);
    [$b, $path] = sf_backup_find(is_string($in['backup'] ?? null) && $in['backup'] !== '' ? $in['backup'] : null);
    $name = preg_replace('/\.zip\z/', '.sfbk', $b['file']);
    sf_audit('backup.download', 'backup', $b['pid'], ['bytes' => (int)$b['bytes'], 'encrypted' => true]);
    sf_meta_set('backup_downloaded_at', sf_now());
    if (isset($GLOBALS['sf_send_out'])) {
        $tmp = sf_backup_dir() . '/.tmp-' . sf_pid() . '.sfbk';
        $o = fopen($tmp, 'xb');
        sf_backup_encrypt($path, $o, $pw);
        fclose($o);
        return $GLOBALS['sf_send_out'] = ['file' => $tmp, 'name' => $name];
    }
    header('Content-Type: application/octet-stream');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $o = fopen('php://output', 'wb');
    sf_backup_encrypt($path, $o, $pw);
    fclose($o);
    exit;
}

// ── המשימות ובדיקת התקינות ─────────────────────────────────

sf_job_register('ops.backup', 'גיבוי יומי: המסד, המפתחות וקובצי המקור — data/backups/, שימור 30 יום', function (): void {
    if (sf_driver() !== 'sqlite') return;
    sf_backup_make('daily');
}, daily: true);

sf_job_register('ops.restore_check', 'בדיקת שחזור חודשית: הגיבוי האחרון למסד זמני, תקינות וספירות', function (): void {
    if (!sf_val('SELECT 1 FROM backups WHERE deleted_at IS NULL')) return;
    $last = sf_val('SELECT MAX(verified_at) FROM backups');
    if ($last && sf_time() - (int)strtotime((string)$last) < SF_BACKUP_VERIFY_EVERY) return;
    $r = sf_backup_verify();
    if (!$r['passed']) throw new RuntimeException('בדיקת השחזור נכשלה');
}, daily: true);

sf_health_register('backup', 'גיבוי ושחזור', function () {
    if (sf_driver() !== 'sqlite') return ['warn', 'המסד אינו SQLite — הגיבוי האוטומטי לא פועל; לגבות ב-cPanel'];
    if (!class_exists('ZipArchive')) return ['bad', 'חסרה ההרחבה zip — אין גיבוי'];
    $ht = sf_data_dir() . '/.htaccess';
    if (!is_file($ht) || !str_contains((string)file_get_contents($ht), 'denied')) return ['bad', 'data/.htaccess חסר — הגיבויים עלולים להיות נגישים מהדפדפן'];
    $b = sf_row('SELECT * FROM backups WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
    if (!$b) return ['warn', 'עוד אין גיבוי — נוצר בלילה הראשון שהמתזמן רץ, או "גיבוי עכשיו" בלשונית 💾'];
    $age = sf_time() - (int)strtotime($b['created_at']);
    $v = sf_row('SELECT verified_at, verify_ok FROM backups WHERE verified_at IS NOT NULL ORDER BY verified_at DESC LIMIT 1');
    $vt = $v ? ' · בדיקת שחזור ' . $v['verified_at'] . ((int)$v['verify_ok'] ? ' ✓' : ' ✗') : ' · עוד לא נבדק שחזור';
    if ($v && !(int)$v['verify_ok']) return ['bad', 'בדיקת השחזור האחרונה נכשלה' . $vt];
    if ($age > 36 * 3600) return ['bad', 'הגיבוי האחרון לפני ' . intdiv($age, 3600) . ' שעות' . $vt];
    if (!$v || sf_time() - (int)strtotime($v['verified_at']) > SF_BACKUP_VERIFY_EVERY + 5 * 86400) return ['warn', 'גיבוי אחרון ' . $b['created_at'] . $vt];
    return ['ok', 'גיבוי אחרון ' . $b['created_at'] . ' (' . round($b['bytes'] / 1048576, 1) . 'MB)' . $vt];
});
