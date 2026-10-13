<?php
/**
 * כרטיסים: לקוחות, ספקים ועורכי דין.
 *
 * כל כרטיס שייך למנהל שיצר אותו (owner_id). כל מנהל — גם המפתח — רואה ועורך רק
 * את שלו. אין מאגר משותף (הוכרע 2026-10-12).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PM_KINDS = ['client', 'supplier', 'lawyer'];
const PM_CHANNELS = ['email', 'whatsapp', 'sms'];
const PM_BANK_FIELDS = ['bank', 'branch', 'account', 'holder'];

function pm_str(array $in, string $key, int $max, bool $required = false, string $label = ''): string
{
    $v = $in[$key] ?? '';
    $v = is_scalar($v) ? trim((string)$v) : '';
    // התווית (field) — משתנה, ומתורגמת לבד (lib/i18n.php)
    if ($required && $v === '') throw new PmError('{field}: שדה חובה', 422, ['field' => $label ?: $key]);
    if (mb_strlen($v) > $max) throw new PmError('{field}: עד {n} תווים', 422, ['field' => $label ?: $key, 'n' => $max]);
    return $v;
}

function pm_phone(array $in, string $key, string $label): string
{
    $v = pm_str($in, $key, 30, false, $label);
    if ($v !== '' && !preg_match('/^\+?[0-9][0-9 \-]{5,25}$/', $v)) throw new PmError('{field}: מספר לא תקין', 422, ['field' => $label]);
    return $v;
}

/**
 * תנאי ה-SQL שמגביל לכרטיסים ולפרויקטים שהמשתמש רשאי לראות: **רק שלו**. גם
 * המפתח — כמנהל פרויקטים הוא רואה רק את שלו; ניהול החשבונות (הגדרות ← מנהלים)
 * הוא משהו אחר, ולא חושף אנשי קשר או פרויקטים של אחרים.
 */
function pm_owner_clause(array $user, string $alias = 'c'): array
{
    // כרטיסים — של הצוות: הוא, ומי שחולק איתו פרויקט כשותף מנהל (pm_team_ids)
    $ids = pm_team_ids((int)$user['id']);
    return ["$alias.owner_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

/** פרויקטים שהמשתמש רואה: שלו, ומה ששותף איתו כשותף מנהל. */
function pm_project_clause(array $user, string $alias = 'p'): array
{
    return ["($alias.owner_id = ? OR $alias.id IN (SELECT project_id FROM project_partners WHERE user_id = ?))", [$user['id'], $user['id']]];
}

/**
 * הצוות של מנהל — מי שאנשי הקשר שלו משותפים איתו: הוא עצמו; בעלי הפרויקטים שהוא שותף בהם; וכל
 * השותפים בפרויקטים של כל אלה. כך ספק שהשותף הוסיף נבחר גם אצל הבעלים, ולהפך. בלי שיתוף — רק הוא.
 */
function pm_team_ids(int $userId): array
{
    $cache = &$GLOBALS['pm_team_cache'];
    if (isset($cache[$userId])) return $cache[$userId];
    $db = pm_db();
    $owners = [$userId];
    $st = $db->prepare('SELECT DISTINCT p.owner_id FROM project_partners x JOIN projects p ON p.id = x.project_id WHERE x.user_id = ?');
    $st->execute([$userId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $o) $owners[] = (int)$o;
    $ids = $owners;
    $in = implode(',', array_map('intval', array_unique($owners)));
    foreach ($db->query("SELECT DISTINCT x.user_id FROM project_partners x JOIN projects p ON p.id = x.project_id WHERE p.owner_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $u) $ids[] = (int)$u;
    return $cache[$userId] = array_values(array_unique($ids));
}

/** אחרי צירוף / הסרה של שותף — המטמון של הבקשה מתאפס. */
function pm_team_reset(): void
{
    $GLOBALS['pm_team_cache'] = [];
}

/** איש קשר שבעליו בצוות של בעל הפרויקט — מותר לשייך אותו לפרויקט. */
function pm_contact_in_team(int $contactOwnerId, int $projectOwnerId): bool
{
    return in_array($contactOwnerId, pm_team_ids($projectOwnerId), true);
}

/** כרטיס שהמשתמש רשאי לראות — או חריגה 404. */
function pm_contact_row(array $user, int $id): array
{
    [$where, $args] = pm_owner_clause($user);
    $st = pm_db()->prepare("SELECT c.* FROM contacts c WHERE c.id = ? AND $where");
    $st->execute(array_merge([$id], $args));
    $row = $st->fetch();
    if (!$row) throw new PmError('הכרטיס לא נמצא', 404);
    return $row;
}

function pm_contact_out(array $row, array $tradeIds = [], ?array $settings = null): array
{
    $out = [
        'id'         => (int)$row['id'],
        'owner_id'   => (int)$row['owner_id'],
        'kind'       => $row['kind'],
        'name'       => $row['name'],
        'company'    => $row['company'],
        'tax_id'     => $row['tax_id'],
        'phone'      => $row['phone'],
        'whatsapp'   => $row['whatsapp'],
        'email'      => $row['email'],
        'address'    => $row['address'],
        'bank'       => json_decode($row['bank_json'], true) ?: (object)[],
        'rating'     => (int)$row['rating'],
        'notes'      => $row['notes'],
        'lang'       => $row['lang'] ?? 'he',
        'vat_exempt' => (bool)($row['vat_exempt'] ?? false),
        'archived'   => (bool)$row['archived'],
        'trades'     => array_map('intval', $tradeIds),
        'updated_at' => $row['updated_at'],
    ];
    if (isset($row['owner_name'])) $out['owner_name'] = $row['owner_name'];
    if (isset($row['lawyer_name'])) $out['lawyer_name'] = $row['lawyer_name'];
    if ($settings !== null) $out['settings'] = $settings;
    return $out;
}

function pm_contacts_list(array $user, string $kind, string $q, bool $archived): array
{
    if (!in_array($kind, PM_KINDS, true)) throw new PmError('סוג כרטיס לא מוכר', 422);
    [$where, $args] = pm_owner_clause($user);
    $sql = "SELECT c.*, m.display_name AS owner_name, l.name AS lawyer_name
              FROM contacts c
              LEFT JOIN users m ON m.id = c.owner_id
              LEFT JOIN client_settings cs ON cs.contact_id = c.id
              LEFT JOIN contacts l ON l.id = cs.lawyer_id
             WHERE $where AND c.kind = ? AND c.archived = ?";
    $args = array_merge($args, [$kind, $archived ? 1 : 0]);
    if ($q !== '') {
        $sql .= ' AND (c.name LIKE ? OR c.company LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
        $like = '%' . strtr($q, ['%' => '', '_' => '']) . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY c.name COLLATE NOCASE';
    $st = pm_db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    $trades = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $t = pm_db()->prepare("SELECT contact_id, trade_id FROM contact_trades WHERE contact_id IN ($in)");
        $t->execute($ids);
        foreach ($t->fetchAll() as $r) $trades[$r['contact_id']][] = $r['trade_id'];
    }
    return array_map(fn($r) => pm_contact_out($r, $trades[$r['id']] ?? []), $rows);
}

function pm_contact_get(array $user, int $id): array
{
    $row = pm_contact_row($user, $id);
    $t = pm_db()->prepare('SELECT trade_id FROM contact_trades WHERE contact_id = ?');
    $t->execute([$id]);
    $settings = null;
    if ($row['kind'] === 'client') $settings = pm_client_settings($id);
    return pm_contact_out($row, $t->fetchAll(PDO::FETCH_COLUMN), $settings);
}

function pm_client_settings(int $id): array
{
    $s = pm_db()->prepare('SELECT * FROM client_settings WHERE contact_id = ?');
    $s->execute([$id]);
    $r = $s->fetch() ?: [];
    return [
        'lawyer_id'       => isset($r['lawyer_id']) ? (int)$r['lawyer_id'] : null,
        'approval_method' => $r['approval_method'] ?? 'click',
        'channels'        => array_values(array_filter(explode(',', $r['channels'] ?? 'email,whatsapp'))),
        'default_payer'   => $r['default_payer'] ?? 'client',
    ];
}

/** יצירה או עדכון. מחזיר את הכרטיס המלא. */
function pm_contact_save(array $user, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        $existing = pm_contact_row($user, $id);
        $kind = $existing['kind'];         // סוג כרטיס לא משתנה אחרי יצירה
        $ownerId = (int)$existing['owner_id'];
    } else {
        $kind = (string)($in['kind'] ?? '');
        if (!in_array($kind, PM_KINDS, true)) throw new PmError('סוג כרטיס לא מוכר', 422);
        $ownerId = $user['id'];
    }

    $email = pm_str($in, 'email', 120, false, 'אימייל');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new PmError('אימייל לא תקין', 422);
    $bankIn = is_array($in['bank'] ?? null) ? $in['bank'] : [];
    $bank = [];
    foreach (PM_BANK_FIELDS as $f) $bank[$f] = pm_str($bankIn, $f, 60, false, 'פרטי בנק');
    $rating = (int)($in['rating'] ?? 0);

    $fields = [
        'name'      => pm_str($in, 'name', 100, true, 'שם'),
        'company'   => pm_str($in, 'company', 100, false, 'שם העסק'),
        'tax_id'    => pm_str($in, 'tax_id', 20, false, 'ח.פ. / ת"ז'),
        'phone'     => pm_phone($in, 'phone', 'טלפון'),        // i18n-ok — מתורגם כמשתנה ב-PmError
        'whatsapp'  => pm_phone($in, 'whatsapp', 'וואטסאפ'),   // i18n-ok
        'email'     => $email,
        'address'   => pm_str($in, 'address', 200, false, 'כתובת'),
        'bank_json' => json_encode(array_filter($bank, 'strlen'), JSON_UNESCAPED_UNICODE),
        'rating'    => max(0, min(5, $rating)),
        'notes'     => pm_str($in, 'notes', 4000, false, 'הערות'),
    ];
    // ספק "עוסק פטור" — בלי מע"מ (אגף הכספים). עדכון בלי השדה — נשאר כמו שהיה.
    if (array_key_exists('vat_exempt', $in)) $fields['vat_exempt'] = !empty($in['vat_exempt']) ? 1 : 0;
    // שפת איש הקשר — הפורטל והמיילים שלו. חדש: מה שנבחר, אחרת שפת הממשק של מי שיצר;
    // עדכון בלי lang — נשאר כמו שהיה.
    $lang = $in['lang'] ?? null;
    if ($lang !== null && $lang !== '') {
        if (!is_string($lang) || !isset(PM_LANGS[$lang])) throw new PmError('שפה לא מוכרת', 422);
        $fields['lang'] = $lang;
    } elseif (!$id) {
        $fields['lang'] = pm_lang();
    }

    $tradeIds = [];
    if ($kind === 'supplier') {
        $raw = is_array($in['trades'] ?? null) ? $in['trades'] : [];
        $tradeIds = array_values(array_unique(array_map('intval', $raw)));
        if ($tradeIds) {
            $inQ = implode(',', array_fill(0, count($tradeIds), '?'));
            $c = pm_db()->prepare("SELECT COUNT(*) FROM trades WHERE id IN ($inQ)");
            $c->execute($tradeIds);
            if ((int)$c->fetchColumn() !== count($tradeIds)) throw new PmError('תחום לא מוכר', 422);
        }
    }

    $settings = null;
    if ($kind === 'client') $settings = pm_validate_client_settings($ownerId, $in['settings'] ?? []);

    $db = pm_db();
    $db->beginTransaction();
    try {
        $now = pm_now();
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $db->prepare("UPDATE contacts SET $set, updated_at = ? WHERE id = ?")
               ->execute(array_merge(array_values($fields), [$now, $id]));
        } else {
            $cols = implode(', ', array_keys($fields));
            $qs = implode(', ', array_fill(0, count($fields), '?'));
            $db->prepare("INSERT INTO contacts (owner_id, kind, $cols, created_at, updated_at) VALUES (?, ?, $qs, ?, ?)")
               ->execute(array_merge([$ownerId, $kind], array_values($fields), [$now, $now]));
            $id = (int)$db->lastInsertId();
        }
        if ($kind === 'supplier') {
            $db->prepare('DELETE FROM contact_trades WHERE contact_id = ?')->execute([$id]);
            $ins = $db->prepare('INSERT INTO contact_trades (contact_id, trade_id) VALUES (?, ?)');
            foreach ($tradeIds as $t) $ins->execute([$id, $t]);
        }
        if ($settings !== null) {
            $db->prepare(
                'INSERT INTO client_settings (contact_id, lawyer_id, approval_method, channels, default_payer)
                 VALUES (?,?,?,?,?)
                 ON CONFLICT(contact_id) DO UPDATE SET lawyer_id = excluded.lawyer_id,
                    approval_method = excluded.approval_method, channels = excluded.channels,
                    default_payer = excluded.default_payer'
            )->execute([$id, $settings['lawyer_id'], $settings['approval_method'],
                        implode(',', $settings['channels']), $settings['default_payer']]);
        }
        pm_audit("user:{$user['id']}", isset($existing) ? 'contact.update' : 'contact.create', 'contact', $id,
                 ['kind' => $kind, 'name' => $fields['name']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return pm_contact_get($user, $id);
}

/**
 * ייבוא מאנשי הקשר במכשיר (או מקובץ vCard): כמה כרטיסים מאותו סוג בבת אחת.
 * מי שכבר קיים אצל המשתמש (אותו טלפון או אימייל) — מדולג.
 */
function pm_contacts_import(array $user, array $in): array
{
    $kind = (string)($in['kind'] ?? '');
    if (!in_array($kind, PM_KINDS, true)) throw new PmError('סוג כרטיס לא מוכר', 422);
    $items = is_array($in['contacts'] ?? null) ? array_slice($in['contacts'], 0, 500) : [];
    if (!$items) throw new PmError('לא נבחרו אנשי קשר', 422);
    $trades = is_array($in['trades'] ?? null) ? $in['trades'] : [];

    $st = pm_db()->prepare('SELECT phone, whatsapp, email FROM contacts WHERE owner_id = ?');
    $st->execute([$user['id']]);
    $phones = $emails = [];
    $norm = fn($p) => ($d = preg_replace('/\D/', '', (string)$p)) === '' ? '' : (str_starts_with($d, '972') ? '0' . substr($d, 3) : $d);
    foreach ($st->fetchAll() as $r) {
        foreach ([$r['phone'], $r['whatsapp']] as $p) if ($n = $norm($p)) $phones[$n] = true;
        if ($r['email'] !== '') $emails[mb_strtolower($r['email'])] = true;
    }

    $created = $skipped = 0;
    $errors = [];
    foreach ($items as $c) {
        if (!is_array($c)) continue;
        $phone = trim(preg_replace('/[^0-9+ \-]/', '', (string)($c['phone'] ?? '')));
        $email = trim((string)($c['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        $name = trim((string)($c['name'] ?? '')) ?: $phone ?: $email;
        if (($phone !== '' && isset($phones[$norm($phone)])) || ($email !== '' && isset($emails[mb_strtolower($email)]))) { $skipped++; continue; }
        try {
            pm_contact_save($user, ['kind' => $kind, 'lang' => $c['lang'] ?? $in['lang'] ?? pm_lang(),
                'name' => mb_substr($name, 0, 100), 'phone' => $phone, 'email' => $email,
                'company' => mb_substr(trim((string)($c['company'] ?? '')), 0, 100), 'address' => mb_substr(trim((string)($c['address'] ?? '')), 0, 200),
                'trades' => $kind === 'supplier' ? $trades : []]);
            $created++;
            if ($phone !== '') $phones[$norm($phone)] = true;
            if ($email !== '') $emails[mb_strtolower($email)] = true;
        } catch (PmError $e) {
            $errors[] = $name . ': ' . $e->localized();
        }
    }
    pm_log('info', 'data', 'contacts.import', ['user' => $user, 'data' => ['kind' => $kind, 'created' => $created, 'skipped' => $skipped, 'errors' => count($errors)]]);
    return ['created' => $created, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 20)];
}

function pm_validate_client_settings(int $ownerId, $raw): array
{
    $raw = is_array($raw) ? $raw : [];
    $lawyerId = (int)($raw['lawyer_id'] ?? 0) ?: null;
    if ($lawyerId) {
        // עו"ד של אותו מנהל (או של הצוות שלו — שותף מנהל) — לא של מנהל זר
        $st = pm_db()->prepare("SELECT owner_id FROM contacts WHERE id = ? AND kind = 'lawyer'");
        $st->execute([$lawyerId]);
        $o = $st->fetchColumn();
        if ($o === false || !pm_contact_in_team((int)$o, $ownerId)) throw new PmError('עורך הדין לא נמצא', 422);
    }
    $method = (string)($raw['approval_method'] ?? 'click');
    if (!in_array($method, ['click', 'signature'], true)) throw new PmError('אופן אישור לא מוכר', 422);
    $payer = (string)($raw['default_payer'] ?? 'client');
    if (!in_array($payer, ['client', 'lawyer'], true)) throw new PmError('משלם לא מוכר', 422);
    if ($payer === 'lawyer' && !$lawyerId) throw new PmError('כדי שהעו"ד ישלם — צריך לבחור עו"ד', 422);
    $channels = is_array($raw['channels'] ?? null) ? $raw['channels'] : [];
    $channels = array_values(array_intersect(PM_CHANNELS, array_map('strval', $channels)));
    return ['lawyer_id' => $lawyerId, 'approval_method' => $method, 'channels' => $channels, 'default_payer' => $payer];
}

function pm_contact_archive(array $user, int $id, bool $archived): array
{
    pm_contact_row($user, $id);
    pm_db()->prepare('UPDATE contacts SET archived = ?, updated_at = ? WHERE id = ?')
        ->execute([$archived ? 1 : 0, pm_now(), $id]);
    pm_audit("user:{$user['id']}", $archived ? 'contact.archive' : 'contact.restore', 'contact', $id);
    return pm_contact_get($user, $id);
}

// ---- תחומים (המפתח עורך) ----

function pm_trades(): array
{
    $rows = pm_db()->query(
        'SELECT t.id, t.name, t.sort, COUNT(ct.contact_id) AS used
           FROM trades t LEFT JOIN contact_trades ct ON ct.trade_id = t.id
          GROUP BY t.id ORDER BY t.sort, t.name'
    )->fetchAll();
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'sort' => (int)$r['sort'], 'used' => (int)$r['used']], $rows);
}

function pm_trade_save(array $user, array $in): array
{
    $name = pm_str($in, 'name', 40, true, 'שם התחום');
    $id = (int)($in['id'] ?? 0);
    $db = pm_db();
    $dup = $db->prepare('SELECT id FROM trades WHERE name = ? AND id != ?');
    $dup->execute([$name, $id]);
    if ($dup->fetchColumn()) throw new PmError('תחום בשם הזה כבר קיים', 409);
    if ($id) {
        $db->prepare('UPDATE trades SET name = ? WHERE id = ?')->execute([$name, $id]);
    } else {
        $sort = (int)$db->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM trades')->fetchColumn();
        $db->prepare('INSERT INTO trades (name, sort) VALUES (?, ?)')->execute([$name, $sort]);
        $id = (int)$db->lastInsertId();
    }
    pm_audit("user:{$user['id']}", 'trade.save', 'trade', $id, ['name' => $name]);
    return pm_trades();
}

function pm_trade_delete(array $user, int $id): array
{
    $u = pm_db()->prepare('SELECT COUNT(*) FROM contact_trades WHERE trade_id = ?');
    $u->execute([$id]);
    if ((int)$u->fetchColumn() > 0) throw new PmError('התחום משויך לספקים — הסר אותו מהם קודם', 409);
    pm_db()->prepare('DELETE FROM trades WHERE id = ?')->execute([$id]);
    pm_audit("user:{$user['id']}", 'trade.delete', 'trade', $id);
    return pm_trades();
}
