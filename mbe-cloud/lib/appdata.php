<?php
/**
 * ענן MBE — שלב 4: נתוני משתמש (appdata). האפיון: docs/spec-4-appdata.md.
 *
 * מסמכי JSON לפי אוסף ומפתח, בשני מרחבים:
 *   user — של המשתמש באפליקציה (אסימון, או חתימה עם חיבור). ההיקף appdata (🗂️) נדרש.
 *   app  — של האפליקציה עצמה. כתיבה רק משרת האפליקציה, חתום ובלי X-Cloud-Connection. הדפדפן קורא בלבד.
 * ה-client תמיד מהחתימה או מה-aud של האסימון — לעולם לא מהגוף. מנהל הענן רואה נפח, לא תוכן.
 */

declare(strict_types=1);

require_once __DIR__ . '/clients.php';

const APPDATA_MAX_DOC = 262144;          // 256KB למסמך
const APPDATA_KEEP = 30 * 86400;         // מצבות וגרסאות — 30 יום
const APPDATA_HISTORY = 5;               // גרסאות לכל מסמך

function migrateAppdata(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS appdata (
            client      TEXT NOT NULL,
            space       TEXT NOT NULL CHECK (space IN ('user','app')),
            account     TEXT NOT NULL,
            collection  TEXT NOT NULL,
            key         TEXT NOT NULL,
            value       TEXT NOT NULL,
            size        INTEGER NOT NULL,
            rev         INTEGER NOT NULL,
            seq         INTEGER NOT NULL,
            updated_at  TEXT NOT NULL,
            updated_by  TEXT NOT NULL DEFAULT '',
            deleted_at  TEXT,
            PRIMARY KEY (client, space, account, collection, key)
        );
        CREATE INDEX IF NOT EXISTS appdata_seq ON appdata(client, space, account, seq);
        CREATE TABLE IF NOT EXISTS appdata_history (
            client TEXT NOT NULL, space TEXT NOT NULL, account TEXT NOT NULL, collection TEXT NOT NULL, key TEXT NOT NULL,
            rev INTEGER NOT NULL, value TEXT NOT NULL, size INTEGER NOT NULL, at TEXT NOT NULL,
            PRIMARY KEY (client, space, account, collection, key, rev)
        );
        -- מונה seq אחד לכל הטבלה (changes)
        CREATE TABLE IF NOT EXISTS appdata_counter (k INTEGER PRIMARY KEY CHECK (k = 1), seq INTEGER NOT NULL);
        INSERT OR IGNORE INTO appdata_counter (k, seq) VALUES (1, 0);
        -- floor: changes עם since נמוך ממנו — 410 gone (clear, או מצבות שנמחקו אחרי 30 יום)
        CREATE TABLE IF NOT EXISTS appdata_floor (
            client TEXT NOT NULL, space TEXT NOT NULL, account TEXT NOT NULL, seq INTEGER NOT NULL,
            PRIMARY KEY (client, space, account)
        );
    ");
    // תקרות לכל אפליקציה (⚙️ ניהול ← לקוחות). NULL — ברירת המחדל מההגדרות
    $cols = array_column($pdo->query('PRAGMA table_info(clients)')->fetchAll(), 'name');
    foreach (['appdata_user_quota', 'appdata_app_quota'] as $c) {
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE clients ADD COLUMN $c INTEGER");
    }
}

// ─── מי קורא ──────────────────────────────────────────────────────────────

/**
 * ההקשר של בקשת appdata: {client, space, account, by, write, acc}.
 * חתום בלי חיבור → מרחב app בלבד, עם כתיבה. אסימון / חתום עם חיבור → ההיקף appdata; במרחב app — קריאה בלבד.
 */
function appdataCtx(string $action, string $raw, array $in): array {
    $space = $in['space'] ?? 'user';
    if (!in_array($space, ['user', 'app'], true)) throw new AppError('מרחב לא מוכר', 400, 'bad_request');
    $h = signedHeaders();
    if (!empty($h['signature']) && ($h['connection'] ?? '') === '') {
        $client = verifySigned($action, $h, $raw);
        if ($space !== 'app') throw new AppError('מרחב user — רק עם חיבור של משתמש', 400, 'bad_request');
        return ['client' => $client['id'], 'space' => 'app', 'account' => '', 'by' => 'server', 'write' => true, 'acc' => null];
    }
    $x = actor($action, 'appdata', $raw);
    if (empty($x['client'])) throw new AppError('נתוני אפליקציות — רק מאפליקציה מחוברת', 403, 'forbidden');
    if ($space === 'app') {
        return ['client' => $x['app'], 'space' => 'app', 'account' => '', 'by' => 'server', 'write' => false, 'acc' => null];
    }
    return ['client' => $x['app'], 'space' => 'user', 'account' => $x['account']['id'], 'by' => $x['account']['id'],
            'write' => true, 'acc' => $x['account']];
}

function appdataWritable(array $ctx): void {
    if (!$ctx['write']) throw new AppError('במרחב app כותב רק השרת של האפליקציה', 403, 'forbidden');
}

// ─── ולידציה ──────────────────────────────────────────────────────────────

function appdataCollection(mixed $c): string {
    if (!is_string($c) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $c)) throw new AppError('שם אוסף לא תקין', 400, 'bad_request');
    return $c;
}

function appdataKey(mixed $k): string {
    if (!is_string($k) || $k === '') throw new AppError('מפתח לא תקין', 400, 'bad_request');
    if (class_exists('Normalizer')) $k = (string) Normalizer::normalize($k, Normalizer::FORM_C);
    if (!mb_check_encoding($k, 'UTF-8') || mb_strlen($k) > 200 || preg_match('/[\x00-\x1F\x7F]/', $k)) {
        throw new AppError('מפתח לא תקין', 400, 'bad_request');
    }
    return $k;
}

/** הערך כ-JSON. null או חסר — 400. מעל 256KB — 413 too_large. */
function appdataValue(array $in): string {
    if (!array_key_exists('value', $in) || $in['value'] === null) throw new AppError('value חסר (null אסור — מוחקים)', 400, 'bad_request');
    $json = json_encode($in['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) throw new AppError('value לא תקין', 400, 'bad_request');
    if (strlen($json) > APPDATA_MAX_DOC) throw new AppError('המסמך גדול מ-256KB', 413, 'too_large', ['max' => APPDATA_MAX_DOC]);
    return $json;
}

function appdataIfRev(array $in): ?int {
    if (!array_key_exists('if_rev', $in) || $in['if_rev'] === null) return null;
    if (!is_int($in['if_rev']) || $in['if_rev'] < 0) throw new AppError('if_rev לא תקין', 400, 'bad_request');
    return $in['if_rev'];
}

// ─── שורות ────────────────────────────────────────────────────────────────

/** השורה, כולל מצבה. */
function appdataRow(array $ctx, string $col, string $key): ?array {
    $st = db()->prepare('SELECT * FROM appdata WHERE client = ? AND space = ? AND account = ? AND collection = ? AND key = ?');
    $st->execute([$ctx['client'], $ctx['space'], $ctx['account'], $col, $key]);
    return $st->fetch() ?: null;
}

function appdataLive(?array $r): bool { return $r !== null && $r['deleted_at'] === null; }

function appdataNextSeq(): int {
    $pdo = db();
    $pdo->exec('UPDATE appdata_counter SET seq = seq + 1 WHERE k = 1');
    return (int) $pdo->query('SELECT seq FROM appdata_counter WHERE k = 1')->fetchColumn();
}

/** if_rev: N — רק אם ה-rev הנוכחי N; 0 — רק אם לא קיים. לא תואם — 409 עם rev ו-value הנוכחיים. */
function appdataCheckRev(?array $r, ?int $ifRev): void {
    if ($ifRev === null) return;
    $cur = appdataLive($r) ? (int) $r['rev'] : 0;
    if ($cur !== $ifRev) {
        throw new AppError('המסמך השתנה בינתיים', 409, 'conflict',
            ['rev' => $cur, 'value' => appdataLive($r) ? json_decode($r['value'], true) : null]);
    }
}

/** הנפח החי של המרחב (user — לזוג אפליקציה+משתמש; app — לאפליקציה). */
function appdataUsed(array $ctx): int {
    $st = db()->prepare('SELECT COALESCE(SUM(size), 0) FROM appdata WHERE client = ? AND space = ? AND account = ? AND deleted_at IS NULL');
    $st->execute([$ctx['client'], $ctx['space'], $ctx['account']]);
    return (int) $st->fetchColumn();
}

function appdataQuota(array $ctx): int {
    $c = getClient($ctx['client']);
    $col = $ctx['space'] === 'user' ? 'appdata_user_quota' : 'appdata_app_quota';
    if ($c && $c[$col] !== null) return (int) $c[$col];
    return (int) setting($col);
}

/** תקרת המרחב, ובמרחב user — גם מכסת החשבון כשההגדרה appdata_counts_in_quota דלוקה (ש1). */
function appdataAssertRoom(array $ctx, int $add, int $replacing): void {
    $quota = appdataQuota($ctx);
    $used = appdataUsed($ctx);
    if ($used - $replacing + $add > $quota) {
        throw new AppError('אין מספיק מקום לנתוני האפליקציה', 413, 'quota_exceeded', ['quota' => $quota, 'used' => $used, 'scope' => $ctx['space']]);
    }
    if ($ctx['space'] === 'user' && setting('appdata_counts_in_quota') === '1' && $add > $replacing) {
        assertRoom($ctx['acc'], $add, $replacing);
    }
}

/** כתיבה: גרסה קודמת להיסטוריה (5, 30 יום), rev עולה, seq חדש. בטרנזקציה אחת. */
function appdataWrite(array $ctx, string $col, string $key, string $json, ?int $ifRev): array {
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $r = appdataRow($ctx, $col, $key);
        appdataCheckRev($r, $ifRev);
        $old = appdataLive($r) ? (int) $r['size'] : 0;
        appdataAssertRoom($ctx, strlen($json), $old);
        $rev = $r ? (int) $r['rev'] + 1 : 1;   // מחיקה ויצירה מחדש ממשיכות את המונה
        if (appdataLive($r)) {
            $pdo->prepare('INSERT OR REPLACE INTO appdata_history (client, space, account, collection, key, rev, value, size, at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$ctx['client'], $ctx['space'], $ctx['account'], $col, $key, (int) $r['rev'], $r['value'], (int) $r['size'], $r['updated_at']]);
            $pdo->prepare('DELETE FROM appdata_history WHERE client = ? AND space = ? AND account = ? AND collection = ? AND key = ? AND rev NOT IN
                           (SELECT rev FROM appdata_history WHERE client = ? AND space = ? AND account = ? AND collection = ? AND key = ? ORDER BY rev DESC LIMIT ' . APPDATA_HISTORY . ')')
                ->execute([$ctx['client'], $ctx['space'], $ctx['account'], $col, $key, $ctx['client'], $ctx['space'], $ctx['account'], $col, $key]);
        }
        $seq = appdataNextSeq();
        $pdo->prepare('INSERT INTO appdata (client, space, account, collection, key, value, size, rev, seq, updated_at, updated_by, deleted_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL)
                       ON CONFLICT(client, space, account, collection, key) DO UPDATE SET value = excluded.value, size = excluded.size,
                       rev = excluded.rev, seq = excluded.seq, updated_at = excluded.updated_at, updated_by = excluded.updated_by, deleted_at = NULL')
            ->execute([$ctx['client'], $ctx['space'], $ctx['account'], $col, $key, $json, strlen($json), $rev, $seq, nowIso(), $ctx['by']]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    appdataPurgeMaybe();
    return ['rev' => $rev, 'seq' => $seq];
}

// ─── הפעולות ──────────────────────────────────────────────────────────────

function appdataGet(array $ctx, array $in): array {
    $r = appdataRow($ctx, appdataCollection($in['collection'] ?? null), appdataKey($in['key'] ?? null));
    if (!appdataLive($r)) throw new AppError('לא נמצא', 404, 'not_found');
    return ['value' => json_decode($r['value'], true), 'rev' => (int) $r['rev'], 'updated_at' => $r['updated_at']];
}

function appdataPut(array $ctx, array $in): array {
    appdataWritable($ctx);
    return appdataWrite($ctx, appdataCollection($in['collection'] ?? null), appdataKey($in['key'] ?? null), appdataValue($in), appdataIfRev($in));
}

function appdataDelete(array $ctx, array $in): array {
    appdataWritable($ctx);
    $col = appdataCollection($in['collection'] ?? null);
    $key = appdataKey($in['key'] ?? null);
    $ifRev = appdataIfRev($in);
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $r = appdataRow($ctx, $col, $key);
        if (!appdataLive($r)) throw new AppError('לא נמצא', 404, 'not_found');
        appdataCheckRev($r, $ifRev);
        $pdo->prepare('INSERT OR REPLACE INTO appdata_history (client, space, account, collection, key, rev, value, size, at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$ctx['client'], $ctx['space'], $ctx['account'], $col, $key, (int) $r['rev'], $r['value'], (int) $r['size'], $r['updated_at']]);
        $seq = appdataNextSeq();
        // מצבה: rev נשמר (יצירה מחדש ממשיכה ממנו), הערך מתרוקן, size 0
        $pdo->prepare("UPDATE appdata SET value = 'null', size = 0, seq = ?, updated_at = ?, updated_by = ?, deleted_at = ?
                       WHERE client = ? AND space = ? AND account = ? AND collection = ? AND key = ?")
            ->execute([$seq, nowIso(), $ctx['by'], nowIso(), $ctx['client'], $ctx['space'], $ctx['account'], $col, $key]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return ['ok' => true, 'seq' => $seq];
}

function appdataLimit(mixed $v): int {
    if ($v === null) return 100;
    if (!is_int($v) || $v < 1 || $v > 500) throw new AppError('limit — 1 עד 500', 400, 'bad_request');
    return $v;
}

/** רשימה בלי ערכים, לפי אוסף ומפתח. cursor — אטום. */
function appdataList(array $ctx, array $in): array {
    $limit = appdataLimit($in['limit'] ?? null);
    $where = 'client = ? AND space = ? AND account = ? AND deleted_at IS NULL';
    $args = [$ctx['client'], $ctx['space'], $ctx['account']];
    if (isset($in['collection'])) { $where .= ' AND collection = ?'; $args[] = appdataCollection($in['collection']); }
    if (isset($in['prefix']) && $in['prefix'] !== '') {
        if (!is_string($in['prefix'])) throw new AppError('prefix לא תקין', 400, 'bad_request');
        $where .= " AND substr(key, 1, ?) = ?";
        array_push($args, strlen($in['prefix']), $in['prefix']);
    }
    if (isset($in['cursor']) && $in['cursor'] !== '') {
        $c = json_decode((string) unb64u((string) $in['cursor']), true);
        if (!is_array($c) || count($c) !== 2) throw new AppError('cursor לא תקין', 400, 'bad_request');
        $where .= ' AND (collection > ? OR (collection = ? AND key > ?))';
        array_push($args, (string) $c[0], (string) $c[0], (string) $c[1]);
    }
    $st = db()->prepare("SELECT collection, key, rev, size, updated_at FROM appdata WHERE $where ORDER BY collection, key LIMIT " . ($limit + 1));
    $st->execute($args);
    $rows = $st->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $last = end($rows);
    return ['items' => array_map(fn($r) => ['collection' => $r['collection'], 'key' => $r['key'], 'rev' => (int) $r['rev'],
                                            'size' => (int) $r['size'], 'updated_at' => $r['updated_at']], $rows),
            'cursor' => $more && $last ? b64u(json_encode([$last['collection'], $last['key']], JSON_UNESCAPED_UNICODE)) : null];
}

function appdataGetMany(array $ctx, array $in): array {
    $col = appdataCollection($in['collection'] ?? null);
    $keys = $in['keys'] ?? null;
    if (!is_array($keys) || !$keys || count($keys) > 100) throw new AppError('keys — 1 עד 100', 400, 'bad_request');
    $keys = array_values(array_unique(array_map('appdataKey', $keys)));
    $st = db()->prepare('SELECT key, value, rev, updated_at FROM appdata WHERE client = ? AND space = ? AND account = ? AND collection = ?
                         AND deleted_at IS NULL AND key IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY key');
    $st->execute([$ctx['client'], $ctx['space'], $ctx['account'], $col, ...$keys]);
    return ['items' => array_map(fn($r) => ['key' => $r['key'], 'value' => json_decode($r['value'], true), 'rev' => (int) $r['rev'],
                                            'updated_at' => $r['updated_at']], $st->fetchAll())];
}

/** שינויים מאז seq, כולל מצבות. since מתחת לרצפה (clear / מצבות שנמחקו), או מעבר למונה — 410 gone. */
function appdataChanges(array $ctx, array $in): array {
    $since = $in['since'] ?? 0;
    if (!is_int($since) || $since < 0) throw new AppError('since לא תקין', 400, 'bad_request');
    $limit = appdataLimit($in['limit'] ?? null);
    $floor = db()->prepare('SELECT seq FROM appdata_floor WHERE client = ? AND space = ? AND account = ?');
    $floor->execute([$ctx['client'], $ctx['space'], $ctx['account']]);
    $floor = (int) ($floor->fetchColumn() ?: 0);
    $top = (int) db()->query('SELECT seq FROM appdata_counter WHERE k = 1')->fetchColumn();
    if ($since < $floor || $since > $top) throw new AppError('השינויים האלה כבר לא נשמרים — טוענים הכול מחדש (list)', 410, 'gone');
    $st = db()->prepare('SELECT collection, key, rev, seq, deleted_at FROM appdata WHERE client = ? AND space = ? AND account = ? AND seq > ?
                         ORDER BY seq LIMIT ' . ($limit + 1));
    $st->execute([$ctx['client'], $ctx['space'], $ctx['account'], $since]);
    $rows = $st->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $last = end($rows);
    return ['changes' => array_map(fn($r) => ['collection' => $r['collection'], 'key' => $r['key'], 'rev' => (int) $r['rev'],
                                              'seq' => (int) $r['seq'], 'deleted' => $r['deleted_at'] !== null], $rows),
            'cursor' => $last ? (int) $last['seq'] : $since, 'more' => $more];
}

function appdataHistory(array $ctx, array $in): array {
    $col = appdataCollection($in['collection'] ?? null);
    $key = appdataKey($in['key'] ?? null);
    $st = db()->prepare('SELECT rev, size, at FROM appdata_history WHERE client = ? AND space = ? AND account = ? AND collection = ? AND key = ?
                         AND at >= ? ORDER BY rev DESC LIMIT ' . APPDATA_HISTORY);
    $st->execute([$ctx['client'], $ctx['space'], $ctx['account'], $col, $key, nowIso(time() - APPDATA_KEEP)]);
    return ['items' => array_map(fn($r) => ['rev' => (int) $r['rev'], 'size' => (int) $r['size'], 'at' => $r['at']], $st->fetchAll())];
}

/** החזרת גרסה ישנה — כתיבה רגילה, rev חדש. */
function appdataRestore(array $ctx, array $in): array {
    appdataWritable($ctx);
    $col = appdataCollection($in['collection'] ?? null);
    $key = appdataKey($in['key'] ?? null);
    if (!is_int($in['rev'] ?? null)) throw new AppError('rev חסר', 400, 'bad_request');
    $st = db()->prepare('SELECT value FROM appdata_history WHERE client = ? AND space = ? AND account = ? AND collection = ? AND key = ? AND rev = ?');
    $st->execute([$ctx['client'], $ctx['space'], $ctx['account'], $col, $key, $in['rev']]);
    $v = $st->fetchColumn();
    if ($v === false) throw new AppError('הגרסה לא נמצאה', 404, 'not_found');
    return appdataWrite($ctx, $col, $key, (string) $v, appdataIfRev($in));
}

function appdataUsage(array $ctx): array {
    return ['used' => appdataUsed($ctx), 'quota' => appdataQuota($ctx)];
}

/** מוחק את כל הנתונים של הזוג (אפליקציה, משתמש), כולל גרסאות. changes מ-since ישן — 410. רק מרחב user. */
function appdataWipe(string $client, string $account): int {
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $n = $pdo->prepare("SELECT COUNT(*) FROM appdata WHERE client = ? AND space = 'user' AND account = ? AND deleted_at IS NULL");
        $n->execute([$client, $account]);
        $n = (int) $n->fetchColumn();
        $pdo->prepare("DELETE FROM appdata WHERE client = ? AND space = 'user' AND account = ?")->execute([$client, $account]);
        $pdo->prepare("DELETE FROM appdata_history WHERE client = ? AND space = 'user' AND account = ?")->execute([$client, $account]);
        $seq = appdataNextSeq();
        $pdo->prepare("INSERT INTO appdata_floor (client, space, account, seq) VALUES (?, 'user', ?, ?)
                       ON CONFLICT(client, space, account) DO UPDATE SET seq = excluded.seq")->execute([$client, $account, $seq]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return $n;
}

function appdataClear(array $ctx, array $in): array {
    if ($ctx['space'] !== 'user') throw new AppError('clear — רק במרחב user', 400, 'bad_request');
    if (($in['confirm'] ?? null) !== 'clear') throw new AppError('נדרש confirm: "clear"', 400, 'bad_request');
    return ['ok' => true, 'deleted' => appdataWipe($ctx['client'], $ctx['account'])];
}

/** מצבות וגרסאות של יותר מ-30 יום. מצבה שנמחקת מעלה את הרצפה של הזוג שלה (changes מתחתיה — 410). */
function appdataPurgeMaybe(bool $force = false): void {
    if (!$force && random_int(1, 50) !== 1) return;
    $pdo = db();
    $cut = nowIso(time() - APPDATA_KEEP);
    $st = $pdo->prepare('SELECT client, space, account, MAX(seq) AS seq FROM appdata WHERE deleted_at IS NOT NULL AND deleted_at < ? GROUP BY client, space, account');
    $st->execute([$cut]);
    foreach ($st->fetchAll() as $r) {
        $pdo->prepare('INSERT INTO appdata_floor (client, space, account, seq) VALUES (?,?,?,?)
                       ON CONFLICT(client, space, account) DO UPDATE SET seq = MAX(seq, excluded.seq)')
            ->execute([$r['client'], $r['space'], $r['account'], (int) $r['seq']]);
    }
    $pdo->prepare('DELETE FROM appdata WHERE deleted_at IS NOT NULL AND deleted_at < ?')->execute([$cut]);
    $pdo->prepare('DELETE FROM appdata_history WHERE at < ?')->execute([$cut]);
}

// ─── דף הענן: "🧩 נתוני אפליקציות" ─────────────────────────────────────────

/** לכל אפליקציה שיש לה נתונים של המשתמש: שם, נפח, מספר מסמכים, עדכון אחרון. בלי תוכן. */
function appdataApps(array $a): array {
    $st = db()->prepare("SELECT d.client, COALESCE(k.name, d.client) AS name, SUM(d.size) AS used, COUNT(*) AS docs, MAX(d.updated_at) AS updated_at
                         FROM appdata d LEFT JOIN clients k ON k.id = d.client
                         WHERE d.space = 'user' AND d.account = ? AND d.deleted_at IS NULL GROUP BY d.client ORDER BY name");
    $st->execute([$a['id']]);
    return ['items' => array_map(fn($r) => ['client' => $r['client'], 'name' => $r['name'], 'used' => (int) $r['used'],
                                            'docs' => (int) $r['docs'], 'updated_at' => $r['updated_at']], $st->fetchAll())];
}

/** 🗑️ מחק נתונים — מדף הענן, של בעל החשבון. */
function appdataWipeOwn(array $a, mixed $client): array {
    if (!is_string($client) || $client === '') throw new AppError('לא נמצא', 404, 'not_found');
    return ['ok' => true, 'deleted' => appdataWipe($client, $a['id'])];
}

/** הנפח של משתמש בכל האפליקציות — למכסה, כשההגדרה appdata_counts_in_quota דלוקה. */
function appdataAccountBytes(string $account): int {
    $st = db()->prepare("SELECT COALESCE(SUM(size), 0) FROM appdata WHERE space = 'user' AND account = ? AND deleted_at IS NULL");
    $st->execute([$account]);
    return (int) $st->fetchColumn();
}

/** ייצוא: מרחב user בלבד, לפי אפליקציה. בלי גרסאות ובלי מרחב app. */
function appdataExport(string $account): array {
    $st = db()->prepare("SELECT client, collection, key, value FROM appdata WHERE space = 'user' AND account = ? AND deleted_at IS NULL ORDER BY client, collection, key");
    $st->execute([$account]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['client']][$r['collection']][$r['key']] = json_decode($r['value'], true);
    return $out;
}

function appdataDeleteAccount(string $account): void {
    $pdo = db();
    foreach (['appdata', 'appdata_history', 'appdata_floor'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE space = 'user' AND account = ?")->execute([$account]);
    }
}

/** ניהול: הנפח לכל אפליקציה (שני המרחבים) — בלי תוכן. */
function appdataAdminUsage(): array {
    $rows = db()->query("SELECT client, space, SUM(size) AS used FROM appdata WHERE deleted_at IS NULL GROUP BY client, space")->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['client']][$r['space']] = (int) $r['used'];
    return $out;
}
