<?php
/**
 * ארגונים — שלב 2: ארגון, תת-ארגון, חברים ותפקידים, קופות, גורמים וסעיפים.
 *
 * מבנה:
 *   ארגון ראשי ──── תתי-ארגונים (רמה אחת, למשל חבר"ק תחת ארגון הקהילות)
 *       │                   │
 *       ├─ חברים            ╰─ קופות משלו
 *       ├─ גורמים, סעיפים   (גורמים, סעיפים וחברים — של הארגון הראשי, משותפים)
 *       ╰─ קופות
 *
 * הרשאות — לפי החברות בארגון הראשי, וחלות גם על תתי-הארגונים שלו:
 *   viewer  צופה: קריאה (ובשלב 3 — הערות)
 *   editor  עורך: הכול חוץ מחברים ומחיקת הארגון
 *   owner   בעלים: הכול. תמיד נשאר לפחות בעלים אחד.
 *
 * כל שינוי נרשם בהיסטוריה של הארגון (org_events): מי, מתי, מה.
 */

declare(strict_types=1);

require_once __DIR__ . '/dev.php';

const ROLES = ['viewer' => 1, 'editor' => 2, 'owner' => 3];
const ROLE_LABELS = ['viewer' => 'צופה', 'editor' => 'עורך', 'owner' => 'בעלים'];
const FUND_KINDS = [
    'operating' => 'שוטף',
    'hekdesh'   => 'הקדש דתי',
    'secular'   => 'הקדש חילוני',
    'fund'      => 'קרן ייעודית',
];
const CONTACT_KINDS = ['supplier' => 'ספק', 'customer' => 'לקוח', 'both' => 'ספק ולקוח'];
const CAT_KINDS = ['expense' => 'הוצאה', 'income' => 'הכנסה'];

function orgs_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS orgs (
            id          INTEGER PRIMARY KEY,
            parent_id   INTEGER REFERENCES orgs(id),
            name        TEXT NOT NULL,
            subtitle    TEXT,
            notes       TEXT,
            created_by  INTEGER NOT NULL REFERENCES users(id),
            created_at  TEXT NOT NULL,
            updated_at  TEXT NOT NULL,
            deleted_at  TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_orgs_parent ON orgs(parent_id);
        -- חברות — רק בארגון ראשי
        CREATE TABLE IF NOT EXISTS org_members (
            org_id    INTEGER NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
            user_id   INTEGER NOT NULL REFERENCES users(id),
            role      TEXT NOT NULL CHECK (role IN ('viewer','editor','owner')),
            added_by  INTEGER REFERENCES users(id),
            added_at  TEXT NOT NULL,
            PRIMARY KEY (org_id, user_id)
        );
        CREATE INDEX IF NOT EXISTS idx_members_user ON org_members(user_id);
        -- קופה: שוטף / הקדש / קרן. כל קופה מאוזנת בפני עצמה ("כסף צבוע")
        CREATE TABLE IF NOT EXISTS funds (
            id          INTEGER PRIMARY KEY,
            org_id      INTEGER NOT NULL REFERENCES orgs(id),
            kind        TEXT NOT NULL CHECK (kind IN ('operating','hekdesh','secular','fund')),
            name        TEXT NOT NULL,
            address     TEXT,
            bank        TEXT,
            notes       TEXT,
            sort        INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL,
            archived_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_funds_org ON funds(org_id, sort);
        -- גורם: ספק / לקוח (שוכר, משלם) / שניהם — של הארגון הראשי
        CREATE TABLE IF NOT EXISTS contacts (
            id          INTEGER PRIMARY KEY,
            org_id      INTEGER NOT NULL REFERENCES orgs(id),
            kind        TEXT NOT NULL CHECK (kind IN ('supplier','customer','both')),
            name        TEXT NOT NULL,
            phone       TEXT,
            email       TEXT,
            tax_id      TEXT,
            notes       TEXT,
            created_at  TEXT NOT NULL,
            archived_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_contacts_org ON contacts(org_id);
        -- סעיף: קטלוג של שמות שורות (משרדיות, עמלות בנק…) — של הארגון הראשי
        CREATE TABLE IF NOT EXISTS categories (
            id          INTEGER PRIMARY KEY,
            org_id      INTEGER NOT NULL REFERENCES orgs(id),
            kind        TEXT NOT NULL CHECK (kind IN ('expense','income')),
            name        TEXT NOT NULL,
            created_at  TEXT NOT NULL,
            archived_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_categories_org ON categories(org_id);
        -- היסטוריה של הארגון: מי שינה מה
        CREATE TABLE IF NOT EXISTS org_events (
            id       INTEGER PRIMARY KEY,
            org_id   INTEGER NOT NULL,
            user_id  INTEGER,
            at       TEXT NOT NULL,
            action   TEXT NOT NULL,
            detail   TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_org_events ON org_events(org_id, id);
        SQL);
}

// ── עזרים ────────────────────────────────────────────────────

function text_in($v, int $max, string $label, bool $required = false): ?string
{
    $v = trim(preg_replace('/[ \t]+/u', ' ', (string)($v ?? '')));
    if ($v === '') {
        if ($required) throw new ApiError("$label: חובה", 422);
        return null;
    }
    if (mb_strlen($v) > $max) throw new ApiError("$label: עד $max תווים", 422);
    return $v;
}

function one_of($v, array $allowed, string $label): string
{
    $v = (string)$v;
    if (!array_key_exists($v, $allowed)) throw new ApiError("$label: ערך לא מוכר", 422);
    return $v;
}

/** רישום להיסטוריה של הארגון הראשי. */
function org_event(int $rootId, ?array $s, string $action, array $detail = []): void
{
    db()->prepare('INSERT INTO org_events (org_id, user_id, at, action, detail) VALUES (?, ?, ?, ?, ?)')
        ->execute([$rootId, $s ? (int)$s['id'] : null, now_iso(), $action, $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null]);
}

/**
 * הארגון והתפקיד של המשתמש בו. $need — התפקיד המינימלי.
 * ארגון שלא קיים, נמחק, או שאין לך בו חברות — 404 (לא מגלים שהוא קיים).
 */
function org_access(array $s, int $orgId, string $need = 'viewer'): array
{
    $st = db()->prepare('SELECT * FROM orgs WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$orgId]);
    $org = $st->fetch() ?: throw new ApiError('הארגון לא נמצא', 404);
    $rootId = (int)($org['parent_id'] ?? $org['id']);
    if ($org['parent_id']) {
        $st->execute([$rootId]);
        if (!$st->fetch()) throw new ApiError('הארגון לא נמצא', 404);
    }
    $st = db()->prepare('SELECT role FROM org_members WHERE org_id = ? AND user_id = ?');
    $st->execute([$rootId, $s['id']]);
    $role = $st->fetchColumn() ?: throw new ApiError('הארגון לא נמצא', 404);
    if (ROLES[$role] < ROLES[$need]) {
        throw new ApiError($need === 'owner' ? 'רק לבעלים של הארגון' : 'אין לך הרשאת עריכה בארגון הזה', 403);
    }
    $org['root_id'] = $rootId;
    $org['role'] = $role;
    return $org;
}

// ── ארגונים ──────────────────────────────────────────────────

function org_list(array $s): array
{
    $st = db()->prepare(
        "SELECT o.id, o.name, o.subtitle, o.updated_at, m.role,
                (SELECT COUNT(*) FROM orgs c WHERE c.parent_id = o.id AND c.deleted_at IS NULL) AS subs,
                (SELECT COUNT(*) FROM funds f JOIN orgs x ON x.id = f.org_id
                  WHERE (x.id = o.id OR x.parent_id = o.id) AND x.deleted_at IS NULL AND f.archived_at IS NULL) AS funds,
                (SELECT COUNT(*) FROM org_members mm WHERE mm.org_id = o.id) AS members
         FROM orgs o JOIN org_members m ON m.org_id = o.id AND m.user_id = ?
         WHERE o.deleted_at IS NULL AND o.parent_id IS NULL
         ORDER BY o.name"
    );
    $st->execute([$s['id']]);
    return ['ok' => true, 'orgs' => array_map(fn($o) => [
        'id' => (int)$o['id'], 'name' => $o['name'], 'subtitle' => $o['subtitle'], 'role' => $o['role'],
        'subs' => (int)$o['subs'], 'funds' => (int)$o['funds'], 'members' => (int)$o['members'], 'updated_at' => $o['updated_at'],
    ], $st->fetchAll())];
}

/** ארגון חדש (המשתמש — בעלים), או תת-ארגון (parent_id; דורש עריכה בארגון הראשי). */
function org_create(array $s, array $b): array
{
    $name = text_in($b['name'] ?? '', 120, 'שם הארגון', true);
    $subtitle = text_in($b['subtitle'] ?? '', 160, 'תת-כותרת');
    $parentId = (int)($b['parent_id'] ?? 0) ?: null;
    $pdo = db();
    if ($parentId) {
        $parent = org_access($s, $parentId, 'editor');
        if ($parent['parent_id']) throw new ApiError('תת-ארגון נפתח רק תחת ארגון ראשי', 422);
    }
    $now = now_iso();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO orgs (parent_id, name, subtitle, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$parentId, $name, $subtitle, $s['id'], $now, $now]);
        $id = (int)$pdo->lastInsertId();
        if (!$parentId) {
            $pdo->prepare('INSERT INTO org_members (org_id, user_id, role, added_by, added_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $s['id'], 'owner', $s['id'], $now]);
        }
        // כל ארגון מתחיל בקופה שוטפת — כמו בכל ההצעות
        $pdo->prepare("INSERT INTO funds (org_id, kind, name, sort, created_at) VALUES (?, 'operating', ?, 0, ?)")
            ->execute([$id, $parentId ? "$name — שוטף" : 'ארגון שוטף', $now]);
        org_event($parentId ?: $id, $s, $parentId ? 'sub_create' : 'org_create', ['name' => $name, 'id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'id' => $id];
}

function org_get(array $s, int $id): array
{
    $org = org_access($s, $id);
    $rootId = $org['root_id'];
    $pdo = db();

    $root = $org;
    if ($org['parent_id']) {
        $st = $pdo->prepare('SELECT id, name FROM orgs WHERE id = ?');
        $st->execute([$rootId]);
        $root = $st->fetch();
    }
    $subs = $pdo->prepare('SELECT id, name, subtitle,
            (SELECT COUNT(*) FROM funds f WHERE f.org_id = o.id AND f.archived_at IS NULL) AS funds
        FROM orgs o WHERE parent_id = ? AND deleted_at IS NULL ORDER BY name');
    $subs->execute([$rootId]);

    $funds = $pdo->prepare('SELECT * FROM funds WHERE org_id = ? AND archived_at IS NULL ORDER BY sort, id');
    $funds->execute([$id]);

    $contacts = $pdo->prepare('SELECT * FROM contacts WHERE org_id = ? AND archived_at IS NULL ORDER BY name');
    $contacts->execute([$rootId]);

    $cats = $pdo->prepare('SELECT * FROM categories WHERE org_id = ? AND archived_at IS NULL ORDER BY kind DESC, name');
    $cats->execute([$rootId]);

    $members = $pdo->prepare('SELECT m.user_id, m.role, m.added_at, u.display_name, u.email
        FROM org_members m JOIN users u ON u.id = m.user_id WHERE m.org_id = ?
        ORDER BY CASE m.role WHEN \'owner\' THEN 0 WHEN \'editor\' THEN 1 ELSE 2 END, u.display_name');
    $members->execute([$rootId]);

    return ['ok' => true,
        'org' => [
            'id' => (int)$org['id'], 'name' => $org['name'], 'subtitle' => $org['subtitle'], 'notes' => $org['notes'],
            'parent' => $org['parent_id'] ? ['id' => (int)$root['id'], 'name' => $root['name']] : null,
            'role' => $org['role'], 'created_at' => $org['created_at'],
        ],
        'subs' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'subtitle' => $x['subtitle'], 'funds' => (int)$x['funds']], $subs->fetchAll()),
        'funds' => array_map(fn($f) => [
            'id' => (int)$f['id'], 'kind' => $f['kind'], 'name' => $f['name'], 'address' => $f['address'],
            'bank' => $f['bank'], 'notes' => $f['notes'], 'sort' => (int)$f['sort'],
        ], $funds->fetchAll()),
        'contacts' => array_map(fn($c) => [
            'id' => (int)$c['id'], 'kind' => $c['kind'], 'name' => $c['name'], 'phone' => $c['phone'],
            'email' => $c['email'], 'tax_id' => $c['tax_id'], 'notes' => $c['notes'],
        ], $contacts->fetchAll()),
        'categories' => array_map(fn($c) => ['id' => (int)$c['id'], 'kind' => $c['kind'], 'name' => $c['name']], $cats->fetchAll()),
        'members' => array_map(fn($m) => [
            'user_id' => (int)$m['user_id'], 'role' => $m['role'], 'name' => $m['display_name'], 'email' => $m['email'],
            'added_at' => $m['added_at'], 'me' => (int)$m['user_id'] === (int)$s['id'],
        ], $members->fetchAll()),
        'labels' => ['roles' => ROLE_LABELS, 'funds' => FUND_KINDS, 'contacts' => CONTACT_KINDS, 'categories' => CAT_KINDS],
    ];
}

function org_update(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'editor');
    $name = text_in($b['name'] ?? '', 120, 'שם הארגון', true);
    $subtitle = text_in($b['subtitle'] ?? '', 160, 'תת-כותרת');
    $notes = text_in($b['notes'] ?? '', 2000, 'הערות');
    db()->prepare('UPDATE orgs SET name = ?, subtitle = ?, notes = ?, updated_at = ? WHERE id = ?')
        ->execute([$name, $subtitle, $notes, now_iso(), $org['id']]);
    org_event($org['root_id'], $s, 'org_update', ['id' => (int)$org['id'], 'name' => $name]);
    return ['ok' => true];
}

/** מחיקה רכה. ארגון ראשי — רק בעלים, ונמחק עם תתי-הארגונים. תת-ארגון — עורך. */
function org_delete(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'editor');
    if (!$org['parent_id'] && $org['role'] !== 'owner') throw new ApiError('רק בעלים מוחק ארגון', 403);
    if ((string)($b['confirm'] ?? '') !== $org['name']) throw new ApiError('לאישור — הקלידו את שם הארגון', 422);
    $now = now_iso();
    db()->prepare('UPDATE orgs SET deleted_at = ? WHERE (id = ? OR parent_id = ?) AND deleted_at IS NULL')
        ->execute([$now, $org['id'], $org['id']]);
    org_event($org['root_id'], $s, $org['parent_id'] ? 'sub_delete' : 'org_delete', ['id' => (int)$org['id'], 'name' => $org['name']]);
    return ['ok' => true];
}

// ── חברים ────────────────────────────────────────────────────

function owners_count(int $rootId): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM org_members WHERE org_id = ? AND role = 'owner'");
    $st->execute([$rootId]);
    return (int)$st->fetchColumn();
}

/** הוספת חבר לפי מייל — של משתמש פעיל באפליקציה. */
function member_add(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'owner');
    $role = one_of($b['role'] ?? 'viewer', ROLES, 'תפקיד');
    $email = valid_email($b['email'] ?? '');
    $st = db()->prepare("SELECT id, display_name, status FROM users WHERE email = ?");
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || $u['status'] !== 'active') {
        throw new ApiError('אין משתמש פעיל עם המייל הזה. הוא צריך להירשם לאפליקציה ולקבל אישור, ואז להוסיף אותו', 404);
    }
    $st = db()->prepare('SELECT role FROM org_members WHERE org_id = ? AND user_id = ?');
    $st->execute([$org['root_id'], $u['id']]);
    if ($st->fetchColumn()) throw new ApiError('המשתמש כבר חבר בארגון', 409);
    db()->prepare('INSERT INTO org_members (org_id, user_id, role, added_by, added_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$org['root_id'], $u['id'], $role, $s['id'], now_iso()]);
    org_event($org['root_id'], $s, 'member_add', ['name' => $u['display_name'], 'role' => $role]);
    return ['ok' => true, 'message' => $u['display_name'] . ' נוסף כ' . ROLE_LABELS[$role]];
}

function member_target(array $org, int $userId): array
{
    $st = db()->prepare('SELECT m.role, u.display_name FROM org_members m JOIN users u ON u.id = m.user_id WHERE m.org_id = ? AND m.user_id = ?');
    $st->execute([$org['root_id'], $userId]);
    return $st->fetch() ?: throw new ApiError('החבר לא נמצא', 404);
}

function member_set(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'owner');
    $userId = (int)($b['user_id'] ?? 0);
    $role = one_of($b['role'] ?? '', ROLES, 'תפקיד');
    $m = member_target($org, $userId);
    if ($m['role'] === 'owner' && $role !== 'owner' && owners_count($org['root_id']) < 2) {
        throw new ApiError('בארגון חייב להישאר לפחות בעלים אחד', 409);
    }
    db()->prepare('UPDATE org_members SET role = ? WHERE org_id = ? AND user_id = ?')->execute([$role, $org['root_id'], $userId]);
    org_event($org['root_id'], $s, 'member_role', ['name' => $m['display_name'], 'from' => $m['role'], 'role' => $role]);
    return ['ok' => true];
}

/** הסרת חבר (בעלים), או עזיבה (כל חבר — user_id שלו). */
function member_remove(array $s, array $b): array
{
    $userId = (int)($b['user_id'] ?? 0);
    $self = $userId === (int)$s['id'];
    $org = org_access($s, (int)($b['org_id'] ?? 0), $self ? 'viewer' : 'owner');
    $m = member_target($org, $userId);
    if ($m['role'] === 'owner' && owners_count($org['root_id']) < 2) {
        throw new ApiError('בארגון חייב להישאר לפחות בעלים אחד — קודם מנו בעלים נוסף', 409);
    }
    db()->prepare('DELETE FROM org_members WHERE org_id = ? AND user_id = ?')->execute([$org['root_id'], $userId]);
    org_event($org['root_id'], $s, $self ? 'member_leave' : 'member_remove', ['name' => $m['display_name']]);
    return ['ok' => true];
}

// ── קופות ────────────────────────────────────────────────────

function fund_save(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'editor');
    $kind = one_of($b['kind'] ?? 'operating', FUND_KINDS, 'סוג הקופה');
    $name = text_in($b['name'] ?? '', 120, 'שם הקופה', true);
    $address = text_in($b['address'] ?? '', 160, 'כתובת');
    $bank = text_in($b['bank'] ?? '', 160, 'חשבון בנק');
    $notes = text_in($b['notes'] ?? '', 2000, 'הערות');
    $id = (int)($b['id'] ?? 0);
    $pdo = db();
    if ($id) {
        $st = $pdo->prepare('SELECT id FROM funds WHERE id = ? AND org_id = ? AND archived_at IS NULL');
        $st->execute([$id, $org['id']]);
        if (!$st->fetch()) throw new ApiError('הקופה לא נמצאה', 404);
        $pdo->prepare('UPDATE funds SET kind = ?, name = ?, address = ?, bank = ?, notes = ? WHERE id = ?')
            ->execute([$kind, $name, $address, $bank, $notes, $id]);
    } else {
        $st = $pdo->prepare('SELECT COALESCE(MAX(sort), -1) + 1 FROM funds WHERE org_id = ?');
        $st->execute([$org['id']]);
        $pdo->prepare('INSERT INTO funds (org_id, kind, name, address, bank, notes, sort, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$org['id'], $kind, $name, $address, $bank, $notes, (int)$st->fetchColumn(), now_iso()]);
        $id = (int)$pdo->lastInsertId();
    }
    org_event($org['root_id'], $s, ($b['id'] ?? null) ? 'fund_update' : 'fund_create', ['name' => $name, 'org' => $org['name']]);
    return ['ok' => true, 'id' => $id];
}

function fund_archive(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'editor');
    $st = db()->prepare('SELECT name FROM funds WHERE id = ? AND org_id = ? AND archived_at IS NULL');
    $st->execute([(int)($b['id'] ?? 0), $org['id']]);
    $name = $st->fetchColumn() ?: throw new ApiError('הקופה לא נמצאה', 404);
    db()->prepare('UPDATE funds SET archived_at = ? WHERE id = ?')->execute([now_iso(), (int)$b['id']]);
    org_event($org['root_id'], $s, 'fund_archive', ['name' => $name, 'org' => $org['name']]);
    return ['ok' => true];
}

/** סדר הקופות — כמו סדר הדפים במסמך. */
function fund_order(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'editor');
    $ids = array_map('intval', (array)($b['ids'] ?? []));
    $st = db()->prepare('UPDATE funds SET sort = ? WHERE id = ? AND org_id = ?');
    foreach ($ids as $i => $id) $st->execute([$i, $id, $org['id']]);
    return ['ok' => true];
}

// ── גורמים ───────────────────────────────────────────────────

function contact_save(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'editor');
    $rootId = $org['root_id'];
    $kind = one_of($b['kind'] ?? 'customer', CONTACT_KINDS, 'סוג');
    $name = text_in($b['name'] ?? '', 120, 'שם', true);
    $phone = text_in($b['phone'] ?? '', 40, 'טלפון');
    $email = text_in($b['email'] ?? '', 190, 'מייל');
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('מייל לא תקין', 422);
    $taxId = text_in($b['tax_id'] ?? '', 20, 'ח"פ / ת"ז');
    $notes = text_in($b['notes'] ?? '', 2000, 'הערות');
    $id = (int)($b['id'] ?? 0);
    if ($id) {
        $st = db()->prepare('UPDATE contacts SET kind = ?, name = ?, phone = ?, email = ?, tax_id = ?, notes = ?
            WHERE id = ? AND org_id = ? AND archived_at IS NULL');
        $st->execute([$kind, $name, $phone, $email, $taxId, $notes, $id, $rootId]);
        if (!$st->rowCount()) throw new ApiError('הגורם לא נמצא', 404);
    } else {
        db()->prepare('INSERT INTO contacts (org_id, kind, name, phone, email, tax_id, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$rootId, $kind, $name, $phone, $email, $taxId, $notes, now_iso()]);
        $id = (int)db()->lastInsertId();
    }
    org_event($rootId, $s, ($b['id'] ?? null) ? 'contact_update' : 'contact_create', ['name' => $name]);
    return ['ok' => true, 'id' => $id];
}

function contact_archive(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'editor');
    $st = db()->prepare('SELECT name FROM contacts WHERE id = ? AND org_id = ? AND archived_at IS NULL');
    $st->execute([(int)($b['id'] ?? 0), $org['root_id']]);
    $name = $st->fetchColumn() ?: throw new ApiError('הגורם לא נמצא', 404);
    db()->prepare('UPDATE contacts SET archived_at = ? WHERE id = ?')->execute([now_iso(), (int)$b['id']]);
    org_event($org['root_id'], $s, 'contact_archive', ['name' => $name]);
    return ['ok' => true];
}

// ── סעיפים ───────────────────────────────────────────────────

function cat_save(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'editor');
    $rootId = $org['root_id'];
    $kind = one_of($b['kind'] ?? 'expense', CAT_KINDS, 'סוג');
    $name = text_in($b['name'] ?? '', 120, 'שם הסעיף', true);
    $id = (int)($b['id'] ?? 0);
    $st = db()->prepare('SELECT id FROM categories WHERE org_id = ? AND kind = ? AND name = ? AND archived_at IS NULL AND id != ?');
    $st->execute([$rootId, $kind, $name, $id]);
    if ($st->fetch()) throw new ApiError('כבר יש סעיף בשם הזה', 409);
    if ($id) {
        $st = db()->prepare('UPDATE categories SET kind = ?, name = ? WHERE id = ? AND org_id = ? AND archived_at IS NULL');
        $st->execute([$kind, $name, $id, $rootId]);
        if (!$st->rowCount()) throw new ApiError('הסעיף לא נמצא', 404);
    } else {
        db()->prepare('INSERT INTO categories (org_id, kind, name, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$rootId, $kind, $name, now_iso()]);
        $id = (int)db()->lastInsertId();
    }
    org_event($rootId, $s, ($b['id'] ?? null) ? 'cat_update' : 'cat_create', ['name' => $name, 'kind' => $kind]);
    return ['ok' => true, 'id' => $id];
}

function cat_archive(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'editor');
    $st = db()->prepare('SELECT name FROM categories WHERE id = ? AND org_id = ? AND archived_at IS NULL');
    $st->execute([(int)($b['id'] ?? 0), $org['root_id']]);
    $name = $st->fetchColumn() ?: throw new ApiError('הסעיף לא נמצא', 404);
    db()->prepare('UPDATE categories SET archived_at = ? WHERE id = ?')->execute([now_iso(), (int)$b['id']]);
    org_event($org['root_id'], $s, 'cat_archive', ['name' => $name]);
    return ['ok' => true];
}

// ── היסטוריה ─────────────────────────────────────────────────

const EVENT_LABELS = [
    'org_create' => 'פתיחת הארגון', 'org_update' => 'עדכון פרטי הארגון', 'org_delete' => 'מחיקת הארגון',
    'sub_create' => 'פתיחת תת-ארגון', 'sub_delete' => 'מחיקת תת-ארגון',
    'member_add' => 'הוספת חבר', 'member_role' => 'שינוי תפקיד', 'member_remove' => 'הסרת חבר', 'member_leave' => 'עזיבת הארגון',
    'fund_create' => 'הוספת קופה', 'fund_update' => 'עדכון קופה', 'fund_archive' => 'הסרת קופה',
    'contact_create' => 'הוספת גורם', 'contact_update' => 'עדכון גורם', 'contact_archive' => 'הסרת גורם',
    'cat_create' => 'הוספת סעיף', 'cat_update' => 'עדכון סעיף', 'cat_archive' => 'הסרת סעיף',
    'dev_force' => 'פעולת מפתח',
];

function org_history(array $s, int $orgId): array
{
    $org = org_access($s, $orgId);
    $st = db()->prepare('SELECT e.*, u.display_name FROM org_events e LEFT JOIN users u ON u.id = e.user_id
        WHERE e.org_id = ? ORDER BY e.id DESC LIMIT 300');
    $st->execute([$org['root_id']]);
    return ['ok' => true, 'events' => array_map(fn($e) => [
        'at' => $e['at'], 'by' => $e['display_name'], 'action' => $e['action'],
        'label' => EVENT_LABELS[$e['action']] ?? $e['action'], 'detail' => json_decode((string)$e['detail'], true),
    ], $st->fetchAll())];
}

// ── אזור הפיתוח: ארגונים, במטא-דאטה בלבד ─────────────────────

function dev_orgs(array $q): array
{
    $where = ['o.parent_id IS NULL'];
    $args = [];
    if (($t = trim((string)($q['q'] ?? ''))) !== '') {
        $where[] = '(o.name LIKE ? OR o.id = ?)';
        array_push($args, "%$t%", (int)$t);
    }
    $st = db()->prepare('SELECT o.id, o.name, o.created_at, o.deleted_at, u.display_name AS creator,
            (SELECT COUNT(*) FROM org_members m WHERE m.org_id = o.id) AS members,
            (SELECT COUNT(*) FROM orgs c WHERE c.parent_id = o.id AND c.deleted_at IS NULL) AS subs
        FROM orgs o JOIN users u ON u.id = o.created_by WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT 200');
    $st->execute($args);
    return ['ok' => true, 'orgs' => array_map(fn($o) => [
        'id' => (int)$o['id'], 'name' => $o['name'], 'creator' => $o['creator'], 'created_at' => $o['created_at'],
        'deleted' => $o['deleted_at'] !== null, 'members' => (int)$o['members'], 'subs' => (int)$o['subs'],
    ], $st->fetchAll())];
}

const DEV_ORG_OPS = ['delete' => 'מחיקת ארגון', 'restore' => 'שחזור ארגון'];

/** אכיפה: מחיקה / שחזור של ארגון, עם סיבה והקלדת המזהה. נרשם ביומן הביקורת ובהיסטוריה של הארגון. */
function dev_org_force(array $dev, array $b): array
{
    $op = (string)($b['op'] ?? '');
    if (!isset(DEV_ORG_OPS[$op])) throw new ApiError('פעולה לא מוכרת', 422);
    $id = (int)($b['id'] ?? 0);
    $reason = mb_substr(trim((string)($b['reason'] ?? '')), 0, 300);
    if (mb_strlen($reason) < 3) throw new ApiError('חובה לכתוב סיבה — היא נשמרת ביומן הביקורת', 422);
    if ((string)($b['confirm'] ?? '') !== (string)$id) throw new ApiError('לאישור — הקלידו את המספר המזהה', 422);
    $st = db()->prepare('SELECT * FROM orgs WHERE id = ? AND parent_id IS NULL');
    $st->execute([$id]);
    $o = $st->fetch() ?: throw new ApiError('הארגון לא נמצא', 404);
    if ($op === 'delete') {
        if ($o['deleted_at']) throw new ApiError('הארגון כבר מחוק', 409);
        db()->prepare('UPDATE orgs SET deleted_at = ? WHERE (id = ? OR parent_id = ?) AND deleted_at IS NULL')->execute([now_iso(), $id, $id]);
    } else {
        if (!$o['deleted_at']) throw new ApiError('הארגון לא מחוק', 409);
        // משחזרים גם את תתי-הארגונים שנמחקו יחד איתו (באותו רגע)
        db()->prepare('UPDATE orgs SET deleted_at = NULL WHERE id = ? OR (parent_id = ? AND deleted_at = ?)')->execute([$id, $id, $o['deleted_at']]);
    }
    dev_audit($dev, $op, 'org', $id, $reason, ['name' => $o['name']]);
    org_event($id, $dev, 'dev_force', ['op' => DEV_ORG_OPS[$op], 'reason' => $reason]);
    return ['ok' => true, 'message' => DEV_ORG_OPS[$op] . ' — בוצע ונרשם ביומן הביקורת'];
}
