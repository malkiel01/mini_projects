<?php
/**
 * הטיוטה המשותפת, נעילת עריכה והיסטוריית שמירות (docs/spec.md, פרק 4).
 *
 * טיוטה אחת לעמוד. שמירה נושאת את ה-rev שעליו נערכה; rev אחר בשרת → 409. עורך רק מי שמחזיק בנעילה —
 * היא פגה אחרי LOCK_TTL שניות בלי דופק. היסטוריה: נקודה אחת לכל משתמש בכל 10 דקות, עד REV_KEEP.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/page.php';

const LOCK_TTL = 120;
const REV_MERGE = 600;
const REV_KEEP = 200;

function draft_row(): array
{
    $row = db()->query('SELECT * FROM draft WHERE id = 1')->fetch();
    if ($row) return $row;
    $json = json_encode(page_template(), JSON_UNESCAPED_UNICODE);
    db()->prepare("INSERT INTO draft (id, json, rev, updated_by, updated_at) VALUES (1, ?, 1, '', ?)")->execute([$json, now_iso()]);
    return db()->query('SELECT * FROM draft WHERE id = 1')->fetch();
}

/** מצב הנעילה: null — פנויה; אחרת {sub, name, since}. */
function lock_state(): ?array
{
    $l = db()->query('SELECT * FROM edit_lock WHERE id = 1')->fetch();
    if (!$l || $l['beat'] < time() - LOCK_TTL) return null;
    return ['sub' => $l['sub'], 'name' => $l['name'], 'since' => gmdate('Y-m-d\TH:i:s\Z', (int)$l['since'])];
}

function lock_view(array $user): array
{
    $l = lock_state();
    return ['mine' => $l && $l['sub'] === $user['sub'], 'by' => $l && $l['sub'] !== $user['sub'] ? $l['name'] : null, 'since' => $l['since'] ?? null];
}

/** take — לוקח אם פנויה או שלי (גם דופק); release — משחרר אם שלי. */
function lock_op(array $user, array $b): array
{
    $pdo = db();
    $op = (string)($b['op'] ?? 'take');
    $pdo->beginTransaction();
    try {
        $l = lock_state();
        if ($op === 'release') {
            if ($l && $l['sub'] === $user['sub']) $pdo->exec('DELETE FROM edit_lock WHERE id = 1');
        } elseif (!$l || $l['sub'] === $user['sub']) {
            $since = $l ? strtotime($l['since']) : time();
            $pdo->prepare('INSERT INTO edit_lock (id, sub, name, since, beat) VALUES (1, ?, ?, ?, ?)
                           ON CONFLICT(id) DO UPDATE SET sub = excluded.sub, name = excluded.name, since = excluded.since, beat = excluded.beat')
                ->execute([$user['sub'], $user['name'], $since, time()]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['lock' => lock_view($user)];
}

function draft_get(array $user): array
{
    $d = draft_row();
    return [
        'page' => json_decode($d['json'], true),
        'rev' => (int)$d['rev'],
        'updated_at' => $d['updated_at'],
        'lock' => lock_view($user),
    ];
}

/** נקודה בהיסטוריה — מתאחדת עם הקודמת של אותו משתמש בתוך REV_MERGE שניות. */
function revision_add(array $user, string $json, int $rev, string $note = ''): void
{
    $pdo = db();
    $last = $pdo->query('SELECT id, sub, ts, note FROM revisions ORDER BY id DESC LIMIT 1')->fetch();
    if ($note === '' && $last && $last['sub'] === $user['sub'] && $last['note'] === '' && $last['ts'] > time() - REV_MERGE) {
        $pdo->prepare('UPDATE revisions SET json = ?, rev = ?, at = ? WHERE id = ?')->execute([$json, $rev, now_iso(), $last['id']]);
    } else {
        $pdo->prepare('INSERT INTO revisions (at, ts, sub, name, rev, json, note) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([now_iso(), time(), $user['sub'], $user['name'], $rev, $json, $note]);
        $pdo->exec('DELETE FROM revisions WHERE id NOT IN (SELECT id FROM revisions ORDER BY id DESC LIMIT ' . REV_KEEP . ')');
    }
}

function draft_write(array $user, array $page, int $rev, string $note = ''): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $d = draft_row();
        if ((int)$d['rev'] !== $rev) throw new ApiError('מישהו אחר שינה את הטיוטה. רענן כדי לראות', 409, ['code' => 'conflict']);
        $l = lock_state();
        if (!$l || $l['sub'] !== $user['sub']) throw new ApiError($l ? "בעריכה אצל {$l['name']}" : 'העריכה שוחררה. רענן', 423, ['code' => 'locked']);
        $json = json_encode($page, JSON_UNESCAPED_UNICODE);
        $new = $rev + 1;
        if ($json !== $d['json']) {
            $pdo->prepare('UPDATE draft SET json = ?, rev = ?, updated_by = ?, updated_at = ? WHERE id = 1')->execute([$json, $new, $user['sub'], now_iso()]);
            revision_add($user, $json, $new, $note);
            $pdo->prepare('UPDATE edit_lock SET beat = ? WHERE id = 1')->execute([time()]);
        } else {
            $new = $rev;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['rev' => $new, 'page' => $page];
}

function draft_save(array $user, array $b): array
{
    return draft_write($user, page_normalize($b['page'] ?? null), (int)($b['rev'] ?? 0));
}

function revisions_list(): array
{
    return ['revisions' => db()->query('SELECT id, at, name, rev, note FROM revisions ORDER BY id DESC LIMIT 100')->fetchAll()];
}

function revision_restore(array $user, array $b): array
{
    $st = db()->prepare('SELECT * FROM revisions WHERE id = ?');
    $st->execute([(int)($b['id'] ?? 0)]);
    $r = $st->fetch() ?: throw new ApiError('הנקודה לא נמצאה', 404);
    $res = draft_write($user, page_normalize(json_decode($r['json'], true)), (int)($b['rev'] ?? 0), 'שחזור מ-' . $r['at']);
    audit_as($user, 'restore', $r['name'] . ' · ' . $r['at']);
    return $res;
}

function render_preview(array $b): array
{
    return page_render(page_normalize($b['page'] ?? null), true);
}
