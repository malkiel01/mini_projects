<?php
declare(strict_types=1);

/**
 * Dropbox לארגון — הליבה (פרוסה 03): טבלאות, לקוח HTTP בשרת, הגבלה לתיקייה הראשית, סנכרון ב-cursor,
 * תיקייה לכל תיק והעלאה. בלי שום פלט ובלי session — משותף ל-api.php, ל-dropbox-webhook.php ול-bin/dropbox-sync.php.
 *
 * סודות: App key / App secret (DBX_GLOBAL_ORG) ואסימון הרענון (לכל ארגון) — רק דרך dbxSecretPut/Get, מוצפנים.
 * אסימון הגישה חי בזיכרון הבקשה בלבד. אף סוד לא נכתב לשגיאה, ליומן או לתשובה.
 * הקובץ אינו נקודת קצה.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/compat.php';

const DBX_CHUNK = 8 * 1024 * 1024;           // העלאה ישירה עד 8MB; מעל — upload session בחלקים של 8MB
const DBX_MAX_FILE = 50 * 1024 * 1024;       // גבול לקובץ
const DBX_THUMB_CACHE_MAX = 200 * 1024 * 1024;

final class DbxError extends RuntimeException {
    /** @param string $tag error_summary של Dropbox (בלי סודות) או קוד פנימי */
    public function __construct(public readonly string $tag, public readonly int $http = 0, string $msg = '') {
        parent::__construct($msg !== '' ? $msg : $tag);
    }
}

/**
 * שורה אחת, והסמן נסגר מיד. סמן פתוח מחזיק נעילת קריאה של SQLite — ובזמן קריאה ל-Dropbox היא חוסמת את ה-COMMIT של
 * בקשות אחרות ("database is locked"). לכן כל שליפה של שורה אחת כאן עוברת בפונקציה הזו.
 */
function dbxRow(PDOStatement $st): ?array {
    $r = $st->fetch();
    $st->closeCursor();
    return is_array($r) ? $r : null;
}

/* ---------- טבלאות: migrateV2() ב-lib/schema.php (מוסיפה בלבד) ---------- */

function dbxOrg(PDO $pdo, int $orgId): array {
    $st = $pdo->prepare('SELECT * FROM dbx_orgs WHERE org_id=?');
    $st->execute([$orgId]);
    $row = dbxRow($st);
    if ($row) return $row;
    $pdo->prepare('INSERT OR IGNORE INTO dbx_orgs(org_id) VALUES(?)')->execute([$orgId]);
    return dbxOrg($pdo, $orgId);
}
function dbxOrgSet(PDO $pdo, int $orgId, array $fields): void {
    dbxOrg($pdo, $orgId);
    $sets = implode(',', array_map(static fn($k) => "$k=?", array_keys($fields)));
    $pdo->prepare("UPDATE dbx_orgs SET $sets WHERE org_id=?")->execute([...array_values($fields), $orgId]);
}
/** שגיאה אחרונה לארגון — רק תגית Dropbox/פנימית, לעולם לא גוף תשובה. */
function dbxOrgError(PDO $pdo, int $orgId, string $tag): void {
    dbxOrgSet($pdo, $orgId, ['last_error' => mb_substr($tag, 0, 200), 'last_error_at' => gmdate('c')]);
}

/* ---------- הגבלה לתיקייה הראשית ---------- */

/**
 * נתיב Dropbox מבקשה → נתיב מנורמל בתוך הראשית, או DbxError('outside_root') לפני כל קריאה ל-Dropbox.
 * נדחים: '..' או '.' כרכיב, תווי בקרה, נתיב שלא מתחיל בראשית, ו-id:/rev:/ns: (מזהה נבדק רק מול הטבלאות שלנו).
 */
function dbxPathInRoot(array $org, string $path): string {
    $root = (string)($org['root_lower'] ?? '');
    if ($root === '') throw new DbxError('no_root');
    if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path) || !str_starts_with($path, '/') || preg_match('~^(id|rev|ns):~i', $path)) {
        throw new DbxError('outside_root');
    }
    foreach (explode('/', substr($path, 1)) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') throw new DbxError('outside_root');
    }
    $lower = mb_strtolower($path);
    if ($lower !== $root && !str_starts_with($lower, $root . '/')) throw new DbxError('outside_root');
    return $path;
}
function dbxLowerInRoot(array $org, string $lower): bool {
    $root = (string)($org['root_lower'] ?? '');
    return $root !== '' && ($lower === $root || str_starts_with($lower, $root . '/'));
}

/* ---------- לקוח HTTP ---------- */

/**
 * כתובות Dropbox. DBX_API_BASE מוחלף רק בבדיקות — ורק לכתובת מקומית (127.0.0.1), כך שגם אם המשתנה ייקבע בשרת
 * בטעות, אסימון לא יישלח לשום מקום אחר.
 */
function dbxBase(string $kind): string {
    $test = getenv('DBX_API_BASE');
    if (is_string($test) && preg_match('~^http://127\.0\.0\.1:\d{2,5}$~D', $test)) return $test;
    return match ($kind) {
        'content' => 'https://content.dropboxapi.com',
        'authorize' => 'https://www.dropbox.com',
        default => 'https://api.dropboxapi.com',
    };
}

/** @return array{0:int,1:string,2:array<string,string>} סטטוס, גוף, כותרות (באותיות קטנות) */
/** $maxBytes > 0 — תקרה לתשובה שנכתבת ל-$sink: מעבר לה ההורדה נקטעת (DbxError too_large). */
function dbxHttp(string $url, array $headers, string $body, int $timeout = 30, $sink = null, int $maxBytes = 0): array {
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => $sink === null, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$respHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ]);
    if ($sink !== null) curl_setopt($ch, CURLOPT_FILE, $sink);
    $tooLarge = false;
    if ($maxBytes > 0) {
        curl_setopt_array($ch, [CURLOPT_MAXFILESIZE => $maxBytes, CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static function ($c, $dlTotal, $dl) use ($maxBytes, &$tooLarge) {
                if ($dl > $maxBytes || $dlTotal > $maxBytes) { $tooLarge = true; return 1; }
                return 0;
            }]);
    }
    $out = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($tooLarge || $err === CURLE_FILESIZE_EXCEEDED) throw new DbxError('too_large', 0);
    if ($err !== 0) throw new DbxError('network', 0);
    return [$status, $sink === null ? (string)$out : '', $respHeaders];
}

/** Dropbox-API-Arg: JSON ב-ASCII בלבד (Dropbox דורשת \uXXXX לתווים שמעל 0x7E). */
function dbxArg(array $arg): string {
    return str_replace("\x7f", '\u007f', json_encode($arg, JSON_UNESCAPED_SLASHES));
}

final class DbxClient {
    private ?string $access = null;

    public function __construct(private PDO $pdo, private int $orgId) {}

    public static function appKey(): ?string { return dbxSecretGet('dropbox_app_key', DBX_GLOBAL_ORG); }
    public static function appSecret(): ?string { return dbxSecretGet('dropbox_app_secret', DBX_GLOBAL_ORG); }

    /** אסימון גישה מאסימון הרענון. invalid_grant → הארגון "צריך חיבור מחדש" — לא נופלים בשקט. */
    private function token(bool $fresh = false): string {
        if ($this->access !== null && !$fresh) return $this->access;
        $refresh = dbxSecretGet('dropbox_refresh', $this->orgId);
        $key = self::appKey(); $secret = self::appSecret();
        if ($refresh === null) throw new DbxError('not_connected');
        if ($key === null || $secret === null) throw new DbxError('no_app_key');
        [$st, $body] = dbxHttp(dbxBase('api') . '/oauth2/token', ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $key, 'client_secret' => $secret]));
        $j = json_decode($body, true);
        if ($st !== 200 || !is_string($j['access_token'] ?? null)) {
            $err = is_array($j) && is_string($j['error'] ?? null) ? $j['error'] : 'token_http_' . $st;
            if ($err === 'invalid_grant') {
                dbxOrgSet($this->pdo, $this->orgId, ['status' => 'reauth']);
                dbxOrgError($this->pdo, $this->orgId, 'invalid_grant');
                throw new DbxError('reauth', $st);
            }
            throw new DbxError($err, $st);
        }
        return $this->access = $j['access_token'];
    }

    /** קריאת RPC (JSON → JSON). 409 → DbxError עם error_summary. */
    public function rpc(string $endpoint, ?array $args): array {
        [$st, $body] = $this->send(dbxBase('api') . '/2/' . $endpoint, ['Content-Type: application/json'],
            $args === null ? 'null' : json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $j = json_decode($body, true);
        if ($st !== 200) throw $this->error($st, $j);
        return is_array($j) ? $j : [];
    }

    /** קריאת content: הגוף נשלח כמו שהוא, התוצאה ב-Dropbox-API-Result. $sink — קובץ פתוח לכתיבת התוכן. */
    public function content(string $endpoint, array $arg, string $data = '', $sink = null, int $maxBytes = 0): array {
        [$st, $body, $h] = $this->send(dbxBase('content') . '/2/' . $endpoint,
            ['Content-Type: application/octet-stream', 'Dropbox-API-Arg: ' . dbxArg($arg)], $data, 120, $sink, $maxBytes);
        if ($st !== 200) {
            if ($sink !== null) throw new DbxError('content_http_' . $st, $st);
            throw $this->error($st, json_decode($body, true));
        }
        // הורדות (תמונה ממוזערת, תצוגה, הורדה): התוצאה בכותרת. העלאות: התוצאה בגוף, כ-JSON.
        $raw = $h['dropbox-api-result'] ?? (str_contains($h['content-type'] ?? '', 'json') ? $body : '{}');
        $res = json_decode($raw, true);
        return ['result' => is_array($res) ? $res : [], 'body' => $body, 'type' => $h['content-type'] ?? ''];
    }

    private function send(string $url, array $headers, string $body, int $timeout = 30, $sink = null, int $maxBytes = 0): array {
        for ($try = 0; ; $try++) {
            $r = dbxHttp($url, [...$headers, 'Authorization: Bearer ' . $this->token($try > 0)], $body, $timeout, $sink, $maxBytes);
            if ($r[0] === 401 && $try === 0) continue;   // אסימון גישה שפג — רענון אחד ונסיון חוזר
            return $r;
        }
    }

    private function error(int $st, mixed $j): DbxError {
        $tag = is_array($j) && is_string($j['error_summary'] ?? null) ? $j['error_summary'] : 'http_' . $st;
        return new DbxError(mb_substr($tag, 0, 200), $st);
    }

    /** רשימה רקורסיבית/המשך מה-cursor; עמוד אחד. */
    public function listFolder(string $path, bool $recursive): array {
        return $this->rpc('files/list_folder', ['path' => $path, 'recursive' => $recursive, 'include_deleted' => false, 'limit' => 2000]);
    }
    public function listContinue(string $cursor): array { return $this->rpc('files/list_folder/continue', ['cursor' => $cursor]); }
}

/* ---------- סנכרון (Dropbox → האפליקציה) ---------- */

/**
 * נעילה לארגון: BEGIN IMMEDIATE קצר על שורת הסנכרון, וחכירה בזמן (lock_until) — כך שני סנכרונים במקביל
 * (webhook + cron + פתיחת מסך) לא רצים יחד, בלי להחזיק את המסד נעול בזמן הקריאות ל-Dropbox.
 */
function dbxLock(PDO $pdo, int $orgId, int $seconds): ?string {
    dbxOrg($pdo, $orgId);
    $token = bin2hex(random_bytes(8));
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $st = $pdo->prepare('UPDATE dbx_orgs SET lock_token=?, lock_until=? WHERE org_id=? AND lock_until < ?');
        $st->execute([$token, time() + $seconds + 30, $orgId, time()]);
        $ok = $st->rowCount() === 1;
        $pdo->exec('COMMIT');
    } catch (Throwable $e) { $pdo->exec('ROLLBACK'); throw $e; }
    return $ok ? $token : null;
}
function dbxUnlock(PDO $pdo, int $orgId, string $token): void {
    $pdo->prepare('UPDATE dbx_orgs SET lock_token=NULL, lock_until=0 WHERE org_id=? AND lock_token=?')->execute([$orgId, $token]);
}

/** התיק שתיקייה שלו מכילה את הנתיב (path_lower), לפי הנתיב העדכני של התיקייה. */
function dbxCaseForPath(PDO $pdo, int $orgId, string $lower): ?string {
    $st = $pdo->prepare('SELECT cf.case_id, f.path_lower FROM case_folders cf JOIN dbx_folders f ON f.org_id=cf.org_id AND f.dbx_id=cf.dbx_folder_id WHERE cf.org_id=?');
    $st->execute([$orgId]);
    foreach ($st->fetchAll() as $r) {
        if (str_starts_with($lower, $r['path_lower'] . '/')) return (string)$r['case_id'];
    }
    return null;
}

/** רשומת קובץ אחת מ-Dropbox → files (לפי dbx_id). מחזיר true אם נוסף קובץ חדש לתיק. */
function dbxUpsertFile(PDO $pdo, int $orgId, array $e, string $via, ?string $by): bool {
    $lower = (string)$e['path_lower'];
    $caseId = dbxCaseForPath($pdo, $orgId, $lower);
    // לפי (org_id, dbx_id): אותו חשבון בשני ארגונים — שורה לכל ארגון, ו-org_id לעולם לא נכתב מחדש
    $st = $pdo->prepare('SELECT id, case_id, deleted FROM files WHERE org_id=? AND dbx_id=?');
    $st->execute([$orgId, (string)$e['id']]);
    $old = dbxRow($st);
    $now = gmdate('c');
    if ($old) {
        $pdo->prepare('UPDATE files SET case_id=?, path=?, path_lower=?, name=?, size=?, rev=?, server_modified=?, deleted=0, updated_at=? WHERE id=? AND org_id=?')
            ->execute([$caseId, $e['path_display'], $lower, $e['name'], (int)($e['size'] ?? 0), $e['rev'] ?? null, $e['server_modified'] ?? null, $now, $old['id'], $orgId]);
        return $caseId !== null && ($old['case_id'] !== $caseId || (int)$old['deleted'] === 1);
    }
    // ON CONFLICT: סנכרון והעלאה (או שני סנכרונים) שרושמים את אותו קובץ באותו רגע — שורה אחת, בלי חריגת UNIQUE
    $pdo->prepare('INSERT INTO files(org_id,case_id,dbx_id,path,path_lower,name,size,rev,server_modified,deleted,added_via,added_by,kind,created_at,updated_at)
        VALUES(?,?,?,?,?,?,?,?,?,0,?,?,\'\',?,?)
        ON CONFLICT(org_id, dbx_id) DO UPDATE SET case_id=excluded.case_id, path=excluded.path, path_lower=excluded.path_lower,
            name=excluded.name, size=excluded.size, rev=excluded.rev, server_modified=excluded.server_modified, deleted=0, updated_at=excluded.updated_at')
        ->execute([$orgId, $caseId, $e['id'], $e['path_display'], $lower, $e['name'], (int)($e['size'] ?? 0), $e['rev'] ?? null,
            $e['server_modified'] ?? null, $via, $by, $now, $now]);
    return $caseId !== null;
}

/** עמוד אחד של רשומות. מחזיר את הקבצים החדשים שנכנסו לתיקים (לאירועים). */
function dbxApplyEntries(PDO $pdo, array $org, array $entries): array {
    $orgId = (int)$org['org_id'];
    $added = [];
    foreach ($entries as $e) {
        $tag = (string)($e['.tag'] ?? '');
        $lower = (string)($e['path_lower'] ?? '');
        if ($lower === '' || !dbxLowerInRoot($org, $lower)) continue;      // הגנה: רק מה שבתוך הראשית
        if ($tag === 'folder') {
            $pdo->prepare('INSERT INTO dbx_folders(org_id,dbx_id,path,path_lower) VALUES(?,?,?,?)
                ON CONFLICT(org_id,dbx_id) DO UPDATE SET path=excluded.path, path_lower=excluded.path_lower')
                ->execute([$orgId, $e['id'], $e['path_display'], $lower]);
            // תיקייה ששמה שונה או שהועברה: הקישור לתיק לפי id, והנתיב מתעדכן
            $pdo->prepare('UPDATE case_folders SET dbx_path=? WHERE org_id=? AND dbx_folder_id=?')->execute([$e['path_display'], $orgId, $e['id']]);
        } elseif ($tag === 'file') {
            if (dbxUpsertFile($pdo, $orgId, $e, 'dropbox', null)) {
                $st = $pdo->prepare('SELECT case_id, name, added_via FROM files WHERE org_id=? AND dbx_id=?');
                $st->execute([$orgId, $e['id']]);
                $r = dbxRow($st);
                if ($r && $r['added_via'] === 'dropbox') $added[] = $r;
            }
        } elseif ($tag === 'deleted') {
            // מסומן נמחק — השורה נשארת במסד
            $pdo->prepare('UPDATE files SET deleted=1, updated_at=? WHERE org_id=? AND deleted=0 AND (path_lower=? OR path_lower LIKE ? ESCAPE \'\\\')')
                ->execute([gmdate('c'), $orgId, $lower, dbxLikePrefix($lower)]);
            $pdo->prepare('DELETE FROM dbx_folders WHERE org_id=? AND (path_lower=? OR path_lower LIKE ? ESCAPE \'\\\')')
                ->execute([$orgId, $lower, dbxLikePrefix($lower)]);
        }
    }
    return $added;
}
function dbxLikePrefix(string $lower): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $lower) . '/%';
}

/** אחרי שיוך תיקייה לתיק: הקבצים שכבר במסד תחתיה עוברים לתיק. */
function dbxReassignFiles(PDO $pdo, int $orgId): void {
    $st = $pdo->prepare('SELECT id, path_lower, case_id FROM files WHERE org_id=?');
    $st->execute([$orgId]);
    $up = $pdo->prepare('UPDATE files SET case_id=? WHERE id=?');
    foreach ($st->fetchAll() as $f) {
        $c = dbxCaseForPath($pdo, $orgId, $f['path_lower']);
        if ($c !== $f['case_id']) $up->execute([$c, $f['id']]);
    }
}

/**
 * סנכרון ארגון אחד, עד $budget שניות. list_folder רקורסיבי בפעם הראשונה, ואחר כך continue עד has_more:false.
 * כל עמוד נכתב יחד עם ה-cursor שלו בטרנזקציה אחת — עצירה באמצע לא מכפילה ולא מדלגת.
 * @return array{ok:bool, pages?:int, added?:int, skipped?:string, error?:string}
 */
function dbxSync(PDO $pdo, int $orgId, float $budget): array {
    $org = dbxOrg($pdo, $orgId);
    if ($org['status'] !== 'connected' || (string)$org['root_id'] === '') return ['ok' => false, 'skipped' => 'not_ready'];
    $lock = dbxLock($pdo, $orgId, (int)ceil($budget));
    if ($lock === null) return ['ok' => true, 'skipped' => 'locked'];
    $deadline = microtime(true) + $budget;
    $pages = 0; $added = 0;
    try {
        $client = new DbxClient($pdo, $orgId);
        $cursor = (string)($org['cursor'] ?? '');
        if ($cursor === '') {
            // הנתיב העדכני של הראשית (אם שונה שמה) — לפני הרשימה המלאה
            $meta = $client->rpc('files/get_metadata', ['path' => $org['root_id']]);
            if (($meta['.tag'] ?? '') === 'folder') {
                dbxOrgSet($pdo, $orgId, ['root_path' => $meta['path_display'], 'root_lower' => $meta['path_lower']]);
                $org = dbxOrg($pdo, $orgId);
            }
        }
        while (true) {
            if ($pages > 0 && microtime(true) >= $deadline) break;
            try {
                $page = $cursor === '' ? $client->listFolder((string)$org['root_id'], true) : $client->listContinue($cursor);
            } catch (DbxError $e) {
                if ($cursor !== '' && str_starts_with($e->tag, 'reset')) {      // Dropbox ביקשה להתחיל מחדש — ה-upsert לפי id לא משכפל
                    dbxOrgSet($pdo, $orgId, ['cursor' => null]);
                    $cursor = '';
                    continue;
                }
                throw $e;
            }
            $pdo->beginTransaction();
            try {
                $newFiles = dbxApplyEntries($pdo, $org, is_array($page['entries'] ?? null) ? $page['entries'] : []);
                $cur = $pdo->prepare('UPDATE dbx_orgs SET cursor=? WHERE org_id=? AND lock_token=? AND lock_until>=?');
                $cur->execute([(string)$page['cursor'], $orgId, $lock, time()]);
                // הנעילה אבדה (פגה וסנכרון אחר תפס אותה) — העמוד לא נכתב, ועוצרים: האחר ממשיך מה-cursor שלו
                if ($cur->rowCount() !== 1) { $pdo->rollBack(); return ['ok' => true, 'skipped' => 'lost_lock', 'pages' => $pages]; }
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            foreach ($newFiles as $f) {
                dbxEvent($orgId, (string)$f['case_id'], 'system', 'file_added', ['file' => [null, $f['name']], 'via' => [null, 'dropbox']]);
                $added++;
            }
            $cursor = (string)$page['cursor'];
            $pages++;
            if (empty($page['has_more'])) break;
        }
        $done = empty($page['has_more']);
        dbxOrgSet($pdo, $orgId, ['last_sync_at' => gmdate('c'), 'sync_pending' => $done ? 0 : 1] + ($done ? ['last_error' => null] : []));
        return ['ok' => true, 'pages' => $pages, 'added' => $added, 'more' => !$done];
    } catch (DbxError $e) {
        if ($e->tag !== 'reauth') dbxOrgError($pdo, $orgId, $e->tag);     // reauth — invalid_grant כבר נרשם
        return ['ok' => false, 'error' => $e->tag];
    } finally {
        dbxUnlock($pdo, $orgId, $lock);
    }
}

/** כל הארגונים המחוברים שיש להם תיקייה ראשית. */
function dbxConnectedOrgs(PDO $pdo): array {
    return array_map('intval', $pdo->query("SELECT org_id FROM dbx_orgs WHERE status='connected' AND root_id IS NOT NULL AND root_id<>'' ORDER BY org_id")
        ->fetchAll(PDO::FETCH_COLUMN));
}

/* ---------- תיקייה לכל תיק ---------- */

/** שם תיקייה בטוח: תווים אסורים ב-Dropbox/Windows → '_', בלי נקודות/רווחים בסוף. */
function dbxSafeName(string $s): string {
    $s = (string)preg_replace('~[\\\\/:*?"<>|\x00-\x1f\x7f]~u', '_', $s);
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    $s = rtrim($s, '. ');
    return $s === '' ? '_' : mb_substr($s, 0, 200);
}
/** השוואת שמות: בלי ניקוד וטעמים, רווחים מכווצים, אותיות קטנות. */
function dbxNormName(string $s): string {
    $s = (string)preg_replace('/[\x{0591}-\x{05C7}]/u', '', $s);
    return mb_strtolower(trim((string)preg_replace('/[\s_]+/u', ' ', $s)));
}

/** פותח `<ראשית>/YYYY-MM/YYYY-MM-DD <שם>/` לתיק (כפילות → " (2)") ומקשר. מחזיר [dbx_folder_id, path]. */
/** @param array $actor ['id','actor'] — המבצע ליומן */
function dbxCreateCaseFolder(PDO $pdo, array $org, array $case, array $actor): array {
    $orgId = (int)$org['org_id'];
    $st = $pdo->prepare('SELECT dbx_folder_id, dbx_path FROM case_folders WHERE org_id=? AND case_id=?');
    $st->execute([$orgId, $case['id']]);
    if ($row = dbxRow($st)) return [$row['dbx_folder_id'], $row['dbx_path']];
    $month = preg_match('/^\d{4}-\d\d$/D', (string)($case['month'] ?? '')) ? $case['month'] : substr((string)($case['date'] ?? ''), 0, 7);
    if (!preg_match('/^\d{4}-\d\d$/D', (string)$month)) $month = gmdate('Y-m');
    $base = trim(((string)($case['date'] ?? '') !== '' ? $case['date'] . ' ' : '') . dbxSafeName((string)$case['name']));
    $client = new DbxClient($pdo, $orgId);
    for ($n = 1; $n <= 30; $n++) {
        $path = dbxPathInRoot($org, $org['root_path'] . '/' . $month . '/' . $base . ($n > 1 ? " ($n)" : ''));
        try {
            $meta = $client->rpc('files/create_folder_v2', ['path' => $path, 'autorename' => false])['metadata'] ?? [];
            break;
        } catch (DbxError $e) {
            if (!str_contains($e->tag, 'conflict')) throw $e;
        }
    }
    if (!isset($meta['id'])) throw new DbxError('folder_conflict');
    dbxLinkFolder($pdo, $org, (string)$case['id'], $meta, (string)$actor['actor']);
    dbxEvent($orgId, (string)$case['id'], 'ui', 'dropbox_folder_created', ['dropbox_folder' => [null, $meta['path_display']]], $actor);
    return [$meta['id'], $meta['path_display']];
}
/**
 * תיקיית התיק, מוכנה להעלאה: נבדקת ב-Dropbox לפי id (הנתיב העדכני נשמר); נמחקה או יצאה מהראשית — הקישור מוסר
 * ונפתחת תיקייה חדשה. בנעילת קובץ לתיק, כך ששתי העלאות ראשונות במקביל לא יפתחו שתי תיקיות.
 * @return string הנתיב (display) של התיקייה
 */
function dbxEnsureCaseFolder(PDO $pdo, array $org, array $case, array $actor): string {
    $orgId = (int)$org['org_id'];
    $dir = dataDir() . '/locks';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $fh = @fopen($dir . '/case-' . hash('sha256', $orgId . '|' . $case['id']) . '.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX)) throw new DbxError('lock');
    try {
        $st = $pdo->prepare('SELECT dbx_folder_id FROM case_folders WHERE org_id=? AND case_id=?');
        $st->execute([$orgId, $case['id']]);
        if ($link = dbxRow($st)) {
            // תמיד לפי id מול Dropbox (קריאה אחת): המטמון עלול להחזיק נתיב ישן של תיקייה שהוזזה, וההעלאה הייתה נוחתת
            // בתיקייה חדשה בנתיב הישן, בלי תיק
            $meta = null;
            try { $meta = (new DbxClient($pdo, $orgId))->rpc('files/get_metadata', ['path' => $link['dbx_folder_id']]); }
            catch (DbxError $e) { if (!str_contains($e->tag, 'not_found')) throw $e; }
            if (is_array($meta) && ($meta['.tag'] ?? '') === 'folder' && dbxLowerInRoot($org, (string)$meta['path_lower'])) {
                $pdo->prepare('INSERT INTO dbx_folders(org_id,dbx_id,path,path_lower) VALUES(?,?,?,?)
                    ON CONFLICT(org_id,dbx_id) DO UPDATE SET path=excluded.path, path_lower=excluded.path_lower')
                    ->execute([$orgId, $meta['id'], $meta['path_display'], $meta['path_lower']]);
                $pdo->prepare('UPDATE case_folders SET dbx_path=? WHERE org_id=? AND case_id=?')->execute([$meta['path_display'], $orgId, $case['id']]);
                return (string)$meta['path_display'];
            }
            $pdo->prepare('DELETE FROM case_folders WHERE org_id=? AND case_id=?')->execute([$orgId, $case['id']]);
            dbxEvent($orgId, (string)$case['id'], 'system', 'dropbox_folder_unlinked', ['dropbox_folder' => [$link['dbx_folder_id'], null], 'via' => [null, 'dropbox']]);
        }
        return dbxCreateCaseFolder($pdo, $org, $case, $actor)[1];
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** קישור תיקייה (מטא-נתונים של Dropbox או שורה מ-dbx_folders) לתיק. */
function dbxLinkFolder(PDO $pdo, array $org, string $caseId, array $meta, string $actor): void {
    $orgId = (int)$org['org_id'];
    $pdo->prepare('INSERT INTO dbx_folders(org_id,dbx_id,path,path_lower) VALUES(?,?,?,?)
        ON CONFLICT(org_id,dbx_id) DO UPDATE SET path=excluded.path, path_lower=excluded.path_lower')
        ->execute([$orgId, $meta['id'], $meta['path_display'], $meta['path_lower']]);
    $pdo->prepare('INSERT INTO case_folders(org_id,case_id,dbx_folder_id,dbx_path,linked_at,linked_by) VALUES(?,?,?,?,?,?)')
        ->execute([$orgId, $caseId, $meta['id'], $meta['path_display'], gmdate('c'), $actor]);
    dbxReassignFiles($pdo, $orgId);
}

/**
 * סריקת התאמה: תת-תיקיות של הראשית (רמה 1, ורמה 2 תחת YYYY-MM) שלא קושרו. מציעה תיק לפי תאריך (אם בשם) ושם
 * מנורמל — התאמה מלאה בלבד. לא מחברת דבר.
 */
function dbxMatchScan(PDO $pdo, array $org): array {
    $orgId = (int)$org['org_id'];
    $root = (string)$org['root_lower'];
    $st = $pdo->prepare('SELECT f.dbx_id, f.path, f.path_lower FROM dbx_folders f
        WHERE f.org_id=? AND NOT EXISTS (SELECT 1 FROM case_folders c WHERE c.org_id=f.org_id AND c.dbx_folder_id=f.dbx_id) ORDER BY f.path_lower');
    $st->execute([$orgId]);
    $linked = $pdo->prepare('SELECT case_id FROM case_folders WHERE org_id=?');
    $linked->execute([$orgId]);
    $linkedCases = array_flip($linked->fetchAll(PDO::FETCH_COLUMN));
    $cases = array_values(array_filter(dbxCases($pdo, $orgId), static fn($c) => !isset($linkedCases[$c['id']])));
    $proposals = []; $unmatched = [];
    foreach ($st->fetchAll() as $f) {
        if (!str_starts_with($f['path_lower'], $root . '/')) continue;
        $rel = explode('/', substr($f['path_lower'], strlen($root) + 1));
        $name = basename((string)$f['path']);
        if (count($rel) === 1 && preg_match('/^\d{4}-\d\d$/D', $rel[0])) continue;              // תיקיית חודש
        if (count($rel) > 2 || (count($rel) === 2 && !preg_match('/^\d{4}-\d\d$/D', $rel[0]))) continue; // תת-תיקייה של תיק
        $date = '';
        if (preg_match('/^(\d{4}-\d\d-\d\d)\s+(.*)$/u', $name, $m)) { $date = $m[1]; $name = $m[2]; }
        $norm = dbxNormName((string)preg_replace('/\s\(\d+\)$/u', '', $name));
        $hits = array_values(array_filter($cases, static fn($c) => dbxNormName($c['name']) === $norm && ($date === '' || $c['date'] === $date)));
        $row = ['folder_id' => $f['dbx_id'], 'path' => $f['path']];
        if (count($hits) === 1) $proposals[] = $row + ['case_id' => $hits[0]['id'], 'case_name' => $hits[0]['name'], 'case_date' => $hits[0]['date']];
        else $unmatched[] = $row;
    }
    return ['proposals' => $proposals, 'unmatched' => $unmatched,
        'cases' => array_map(static fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'date' => $c['date']], $cases)];
}

/* ---------- העלאה (האפליקציה → Dropbox) ---------- */

/** סוגים מותרים: תמונה, PDF, מסמכי Office. לפי סיומת וגם לפי התוכן (finfo). */
function dbxAllowedUpload(string $name, string $tmp): bool {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $exts = ['jpg','jpeg','png','gif','webp','heic','heif','bmp','tif','tiff','pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','rtf'];
    if (!in_array($ext, $exts, true)) return false;
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (str_contains($mime, 'svg') || str_contains($mime, 'html') || str_contains($mime, 'xml') && !str_contains($mime, 'openxml')) return false;
    return match (true) {
        in_array($ext, ['jpg','jpeg','png','gif','webp','heic','heif','bmp','tif','tiff'], true) => str_starts_with($mime, 'image/'),
        $ext === 'pdf' => $mime === 'application/pdf',
        default => $mime !== 'text/x-php' && !str_starts_with($mime, 'text/x-'),
    };
}

/**
 * העלאת קובץ מקומי לתיקיית התיק: עד 8MB — files/upload, מעל — upload session בחלקים של 8MB.
 * mode:add + autorename. מחזיר את המטא-נתונים של Dropbox.
 */
function dbxUploadFile(PDO $pdo, array $org, string $folderPath, string $tmp, string $name): array {
    $size = (int)filesize($tmp);
    $target = dbxPathInRoot($org, rtrim($folderPath, '/') . '/' . dbxSafeName($name));
    $commit = ['path' => $target, 'mode' => 'add', 'autorename' => true, 'mute' => false];
    $client = new DbxClient($pdo, (int)$org['org_id']);
    if ($size <= DBX_CHUNK) {
        return $client->content('files/upload', $commit, (string)file_get_contents($tmp))['result'];
    }
    $fh = fopen($tmp, 'rb');
    try {
        $first = (string)fread($fh, DBX_CHUNK);
        $sid = (string)($client->content('files/upload_session/start', ['close' => false], $first)['result']['session_id'] ?? '');
        if ($sid === '') throw new DbxError('upload_session');
        $offset = strlen($first);
        while ($offset < $size) {
            $chunk = (string)fread($fh, DBX_CHUNK);
            if ($chunk === '') throw new DbxError('upload_read');
            if ($offset + strlen($chunk) >= $size) {
                return $client->content('files/upload_session/finish', ['cursor' => ['session_id' => $sid, 'offset' => $offset], 'commit' => $commit], $chunk)['result'];
            }
            $client->content('files/upload_session/append_v2', ['cursor' => ['session_id' => $sid, 'offset' => $offset], 'close' => false], $chunk);
            $offset += strlen($chunk);
        }
        throw new DbxError('upload_incomplete');
    } finally {
        fclose($fh);
    }
}

/** קישור "פתיחה ב-Dropbox" לנתיב בחשבון (לא קישור שיתוף). */
function dbxWebUrl(string $path, bool $isFolder): string {
    $enc = static fn(string $p) => implode('/', array_map('rawurlencode', explode('/', ltrim($p, '/'))));
    if ($isFolder) return 'https://www.dropbox.com/home/' . $enc($path);
    return 'https://www.dropbox.com/home/' . $enc(dirname($path) === '/' ? '' : dirname($path)) . '?preview=' . rawurlencode(basename($path));
}

/** מטמון תמונות ממוזערות: עד 200MB, הישן נמחק ראשון. */
function dbxThumbDir(): string {
    $dir = __DIR__ . '/../data/cache/thumbs';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}
function dbxThumbPrune(string $dir, int $max = DBX_THUMB_CACHE_MAX): void {
    $files = [];
    $total = 0;
    foreach (glob($dir . '/*.jpg') ?: [] as $f) { $s = (int)@filesize($f); $files[$f] = [(int)@filemtime($f), $s]; $total += $s; }
    if ($total <= $max) return;
    uasort($files, static fn($a, $b) => $a[0] <=> $b[0]);
    foreach ($files as $f => [$t, $s]) {
        if ($total <= $max) break;
        @unlink($f);
        $total -= $s;
    }
}
