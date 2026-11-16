<?php
/**
 * העריכה — פרוסה 02 (docs/spec/02-editing.md): מחירים, שורות, חתימות, מחירון חדש, שינוי תאריך ומחיקה.
 * כל כתיבה — בטרנזקציה, ונרשמת ביומן (audit) עם הערך הקודם, כך ששום ערך לא אובד.
 * מחירון שכבר בתוקף (תאריכו ≤ היום) — רק עם confirm_in_effect; בלעדיו 409 {"need_confirm": true}.
 * השינוי חל רק על המחירון שנבחר: שורות ומחירים שייכים למחירון אחד.
 * פרוסה 03: מחירון שייך לקבוצה אחת, ולכל שורה מחיר אחד — הרשומה ב-prices בקבוצה של המחירון. רשומות של הקבוצה
 * השנייה (מלפני 03) לא נקראות ולא נכתבות.
 */

declare(strict_types=1);

const GP_MAX_AMOUNT = 10000000;

function gp_tx(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $out = $fn($pdo);
        $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function gp_is_future(array $e): bool
{
    return $e['effective_from'] > gp_today();
}

/** מחירון שכבר בתוקף — לא נוגעים בו בלי אישור מפורש. */
function gp_guard(array $e, array $in): void
{
    if (!gp_is_future($e) && ($in['confirm_in_effect'] ?? false) !== true) {
        throw new ApiError('המחירון הזה כבר בתוקף. לתקן אותו?', 409, ['need_confirm' => true]);
    }
}

function gp_touch(int $eid): void
{
    db()->prepare('UPDATE editions SET updated_at = ? WHERE id = ?')->execute([now_iso(), $eid]);
}

function gp_row(int $eid, string $pid): array
{
    $st = db()->prepare('SELECT * FROM rows WHERE edition_id = ? AND pid = ?');
    $st->execute([$eid, $pid]);
    return $st->fetch() ?: throw new ApiError('השורה לא נמצאה', 404);
}

/** null / "" = אין מחיר. אחרת שלם 0–10,000,000 — מספר או מחרוזת ספרות בלבד. */
function gp_amount(mixed $v): ?int
{
    if ($v === null || $v === '') return null;
    $n = match (true) {
        is_int($v) => $v,
        is_string($v) && preg_match('/^\d{1,9}$/', trim($v)) === 1 => (int)trim($v),
        default => -1,
    };
    if ($n < 0 || $n > GP_MAX_AMOUNT) throw new ApiError('סכום לא תקין — מספר שלם בשקלים, מ-0 עד 10,000,000');
    return $n;
}

/** טקסט בשורה אחת, בלי תווי בקרה. */
function gp_text(mixed $v, int $max, string $what): string
{
    if (!is_string($v)) throw new ApiError("$what — טקסט לא תקין");
    $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');
    if (mb_strlen($s) > $max) throw new ApiError("$what ארוך מדי (עד $max תווים)");
    return $s;
}

function gp_date(mixed $v): string
{
    $s = is_string($v) ? trim($v) : '';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s || $s < '2000-01-01' || $s > '2099-12-31') throw new ApiError('תאריך לא תקין');
    return $s;
}

/** התאריך ייחודי בתוך הקבוצה בלבד: ישראל 01/07 וחו"ל 01/07 — מותר. */
function gp_date_free(string $date, string $audience, int $exceptId = 0): void
{
    $st = db()->prepare('SELECT 1 FROM editions WHERE effective_from = ? AND audience = ? AND id <> ?');
    $st->execute([$date, $audience, $exceptId]);
    if ($st->fetchColumn()) throw new ApiError('כבר יש מחירון בתאריך הזה', 409);
}

/** הסדר מחדש: sort = 10, 20, 30… לפי רשימת המזהים. */
function gp_renumber(array $ids): void
{
    $st = db()->prepare('UPDATE rows SET sort = ? WHERE id = ?');
    foreach (array_values($ids) as $i => $id) $st->execute([($i + 1) * 10, $id]);
}

function gp_row_ids(int $eid): array
{
    $st = db()->prepare('SELECT id FROM rows WHERE edition_id = ? ORDER BY sort, id');
    $st->execute([$eid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** כל רשומות המחיר של שורה, לפי קבוצה (ליומן במחיקה — כדי ששום ערך לא יאבד) */
function gp_row_prices(int $rowId): array
{
    $out = [];
    $st = db()->prepare('SELECT audience, amount FROM prices WHERE row_id = ? ORDER BY audience');
    $st->execute([$rowId]);
    foreach ($st as $p) $out[$p['audience']] = $p['amount'] === null ? null : (int)$p['amount'];
    return $out;
}

/* ===== מחיר ===== */

function gp_price_set(int $uid, array $in): array
{
    $e = gp_edition_row((string)($in['pid'] ?? ''));
    $r = gp_row((int)$e['id'], (string)($in['row'] ?? ''));
    if (!in_array($r['kind'], GP_PRICED, true)) throw new ApiError('לשורה הזו אין מחיר');
    $aud = $e['audience'];   // לא נדרש; נשלח ושונה מקבוצת המחירון — שגיאה, כדי שמחיר לא ייכנס לרשימה הלא נכונה
    if (isset($in['audience']) && $in['audience'] !== $aud) throw new ApiError('המחירון הזה ל' . GP_AUDIENCES[$aud] . ' — אי אפשר להזין בו מחיר לקבוצה אחרת');
    $new = gp_amount($in['amount'] ?? null);
    gp_guard($e, $in);
    return gp_tx(function (PDO $pdo) use ($uid, $e, $r, $aud, $new) {
        $old = gp_row_prices((int)$r['id'])[$aud] ?? null;
        if ($old !== $new) {   // שני חלונות: האחרון גובר, וכל שינוי נרשם עם מה שהיה רגע לפניו
            $pdo->prepare('INSERT INTO prices (row_id, audience, amount) VALUES (?, ?, ?)
                ON CONFLICT (row_id, audience) DO UPDATE SET amount = excluded.amount')->execute([$r['id'], $aud, $new]);
            gp_audit($uid, 'price', (int)$e['id'], ['row' => $r['pid'], 'label' => $r['label'], 'audience' => $aud, 'old' => $old, 'new' => $new]);
            gp_touch((int)$e['id']);
        }
        return ['ok' => true, 'amount' => $new];
    });
}

/* ===== שורות ===== */

function gp_row_save(int $uid, array $in): array
{
    $e = gp_edition_row((string)($in['pid'] ?? ''));
    $eid = (int)$e['id'];
    $kind = (string)($in['kind'] ?? '');
    if (!in_array($kind, GP_KINDS, true)) throw new ApiError('סוג שורה לא מוכר');
    $indent = $in['indent'] ?? 0;
    if (!in_array($indent, [0, 1, '0', '1', true, false], true)) throw new ApiError('הזחה — 0 או 1');
    $label = gp_text($in['label'] ?? null, 200, 'הטקסט');
    if ($label === '') throw new ApiError('צריך טקסט לשורה');
    $new = ['kind' => $kind, 'indent' => (int)$indent, 'label' => $label];
    $r = ($in['row'] ?? '') !== '' ? gp_row($eid, (string)$in['row']) : null;
    $after = !$r && ($in['after'] ?? '') !== '' ? gp_row($eid, (string)$in['after']) : null;
    gp_guard($e, $in);

    return gp_tx(function (PDO $pdo) use ($uid, $eid, $r, $after, $new) {
        if ($r) {
            $old = ['kind' => $r['kind'], 'indent' => (int)$r['indent'], 'label' => $r['label']];
            if ($old !== $new) {
                $pdo->prepare('UPDATE rows SET kind = ?, indent = ?, label = ? WHERE id = ?')->execute([$new['kind'], $new['indent'], $new['label'], $r['id']]);
                gp_audit($uid, 'row-edit', $eid, ['row' => $r['pid'], 'old' => $old, 'new' => $new]);
                gp_touch($eid);
            }
            return ['ok' => true, 'row' => $r['pid']];
        }
        $ids = gp_row_ids($eid);
        $pid = gp_pid();
        $pdo->prepare('INSERT INTO rows (edition_id, sort, kind, indent, label, pid) VALUES (?, 0, ?, ?, ?, ?)')
            ->execute([$eid, $new['kind'], $new['indent'], $new['label'], $pid]);
        $at = $after ? array_search((int)$after['id'], $ids, true) + 1 : count($ids);   // מתחת ל-after, או בסוף
        array_splice($ids, $at, 0, [(int)$pdo->lastInsertId()]);
        gp_renumber($ids);
        gp_audit($uid, 'row-add', $eid, ['row' => $pid, 'after' => $after['pid'] ?? null] + $new);
        gp_touch($eid);
        return ['ok' => true, 'row' => $pid];
    });
}

function gp_row_delete(int $uid, array $in): array
{
    $e = gp_edition_row((string)($in['pid'] ?? ''));
    $r = gp_row((int)$e['id'], (string)($in['row'] ?? ''));
    gp_guard($e, $in);
    return gp_tx(function (PDO $pdo) use ($uid, $e, $r) {
        $prices = gp_row_prices((int)$r['id']);
        $pdo->prepare('DELETE FROM prices WHERE row_id = ?')->execute([$r['id']]);
        $pdo->prepare('DELETE FROM rows WHERE id = ?')->execute([$r['id']]);
        gp_audit($uid, 'row-delete', (int)$e['id'], ['row' => $r['pid'], 'kind' => $r['kind'], 'indent' => (int)$r['indent'],
            'label' => $r['label'], 'price' => in_array($r['kind'], GP_PRICED, true) ? ($prices[$e['audience']] ?? null) : null, 'prices' => $prices]);
        gp_touch((int)$e['id']);
        return ['ok' => true];
    });
}

function gp_row_move(int $uid, array $in): array
{
    $e = gp_edition_row((string)($in['pid'] ?? ''));
    $r = gp_row((int)$e['id'], (string)($in['row'] ?? ''));
    $dir = (string)($in['dir'] ?? '');
    if ($dir !== 'up' && $dir !== 'down') throw new ApiError('כיוון לא מוכר');
    gp_guard($e, $in);
    return gp_tx(function () use ($uid, $e, $r, $dir) {
        $ids = gp_row_ids((int)$e['id']);
        $i = array_search((int)$r['id'], $ids, true);
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($j < 0 || $j >= count($ids)) return ['ok' => true, 'moved' => false];   // כבר בקצה
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        gp_renumber($ids);
        gp_audit($uid, 'row-move', (int)$e['id'], ['row' => $r['pid'], 'label' => $r['label'], 'dir' => $dir]);
        gp_touch((int)$e['id']);
        return ['ok' => true, 'moved' => true];
    });
}

/* ===== מקטעים (04-structure-edit.md סעיף 5) ===== */

/** השורות של מחירון לפי הסדר — מה ש-gp_layout צריך, ועוד id/pid/label */
function gp_rows_ordered(int $eid): array
{
    $st = db()->prepare('SELECT id, pid, kind, indent, label FROM rows WHERE edition_id = ? ORDER BY sort, id');
    $st->execute([$eid]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'indent' => (int)$r['indent']] + $r, $st->fetchAll());
}

/** הסוגים של "מקטע חדש" לכל קבוצה: כותרת ראשית (section בישראל, band בחו"ל) או כותרת משנה */
const GP_BLOCK_KINDS = ['il' => ['section', 'subsection'], 'abroad' => ['band', 'subsection']];

/**
 * מקטע חדש: כותרת ושורת item ריקה ("שורה חדשה", בלי מחיר) מיד אחרי after. after ריק — בסוף אזור המחירים, לפני האגרות.
 */
function gp_block_add(int $uid, array $in): array
{
    $e = gp_edition_row((string)($in['pid'] ?? ''));
    $eid = (int)$e['id'];
    $kind = (string)($in['kind'] ?? '');
    if (!in_array($kind, GP_BLOCK_KINDS[$e['audience']], true)) {
        throw new ApiError($kind === 'band' ? 'פס בית עלמין — רק במחירון לתושבי חו"ל'
            : ($kind === 'section' ? 'כותרת חלקות — רק במחירון לתושבי ישראל' : 'סוג מקטע לא מוכר'));
    }
    $label = gp_text($in['label'] ?? null, 200, 'השם');
    if ($label === '') throw new ApiError('צריך שם למקטע');
    $after = ($in['after'] ?? '') !== '' ? gp_row($eid, (string)$in['after']) : null;
    gp_guard($e, $in);

    return gp_tx(function (PDO $pdo) use ($uid, $eid, $kind, $label, $after) {
        $rows = gp_rows_ordered($eid);
        $ids = array_column($rows, 'id');
        $at = $after ? array_search((int)$after['id'], $ids, true) + 1 : gp_layout($rows)['fee_start'];
        $ins = $pdo->prepare('INSERT INTO rows (edition_id, sort, kind, indent, label, pid) VALUES (?, 0, ?, 0, ?, ?)');
        $hp = gp_pid();
        $ins->execute([$eid, $kind, $label, $hp]);
        $hid = (int)$pdo->lastInsertId();
        $ip = gp_pid();
        $ins->execute([$eid, 'item', 'שורה חדשה', $ip]);
        array_splice($ids, $at, 0, [$hid, (int)$pdo->lastInsertId()]);
        gp_renumber($ids);
        gp_audit($uid, 'block-add', $eid, ['row' => $hp, 'item' => $ip, 'after' => $after['pid'] ?? null, 'kind' => $kind, 'label' => $label]);
        gp_touch($eid);
        return ['ok' => true, 'row' => $hp, 'item' => $ip];
    });
}

/**
 * מחיקת מקטע: כותרת וכל השורות שבמקטע שלה (gp_layout), בטרנזקציה אחת.
 * ביומן — הכותרת, השורה שלפניה, וכל שורה עם הטקסט והמחיר (וכל רשומות המחיר), כדי שאפשר לשחזר ביד.
 */
function gp_block_delete(int $uid, array $in): array
{
    $e = gp_edition_row((string)($in['pid'] ?? ''));
    $eid = (int)$e['id'];
    $r = gp_row($eid, (string)($in['row'] ?? ''));
    if (!isset(GP_LEVELS[$r['kind']])) throw new ApiError('אפשר למחוק כך רק כותרת של מקטע — שורה רגילה נמחקת לבד');
    gp_guard($e, $in);

    return gp_tx(function (PDO $pdo) use ($uid, $e, $eid, $r) {
        $rows = gp_rows_ordered($eid);
        $i = array_search((int)$r['id'], array_column($rows, 'id'), true);
        $end = gp_layout($rows)['ends'][$i];
        $gone = [];
        $delP = $pdo->prepare('DELETE FROM prices WHERE row_id = ?');
        $delR = $pdo->prepare('DELETE FROM rows WHERE id = ?');
        for ($j = $i; $j < $end; $j++) {
            $x = $rows[$j];
            $prices = gp_row_prices($x['id']);
            if ($j > $i) $gone[] = ['pid' => $x['pid'], 'kind' => $x['kind'], 'indent' => $x['indent'], 'label' => $x['label'],
                'price' => in_array($x['kind'], GP_PRICED, true) ? ($prices[$e['audience']] ?? null) : null, 'prices' => $prices];
            $delP->execute([$x['id']]);
            $delR->execute([$x['id']]);
        }
        gp_audit($uid, 'block-delete', $eid, ['row' => $r['pid'], 'kind' => $r['kind'], 'label' => $r['label'],
            'after' => $i > 0 ? $rows[$i - 1]['pid'] : null, 'rows' => $gone]);
        gp_touch($eid);
        return ['ok' => true, 'deleted' => $end - $i];
    });
}

/* ===== חתימות ===== */

/** החתימות — סט אחד לכל ההדפסות, במסך ⚙️ הגדרות (הכרעת הבעלים 12:20). ביומן בלי מחירון. */
function gp_signatures_set(int $uid, array $in): array
{
    $s = $in['signatures'] ?? null;
    if (!is_array($s) || count($s) !== 3 || !array_is_list($s)) throw new ApiError('צריך שלוש חתימות');
    $new = array_map(fn($x) => is_array($x) ? ['name' => gp_text($x['name'] ?? '', 100, 'השם'), 'role' => gp_text($x['role'] ?? '', 100, 'התפקיד')]
        : throw new ApiError('חתימה לא תקינה'), $s);
    return gp_tx(function (PDO $pdo) use ($uid, $new) {
        $old = gp_signatures();
        if ($old !== $new) {
            $pdo->prepare("UPDATE settings SET value = ?, updated_by = ?, updated_at = ? WHERE key = 'signatures'")
                ->execute([json_encode($new, JSON_UNESCAPED_UNICODE), $uid ?: null, now_iso()]);
            gp_audit($uid, 'signatures-set', null, ['old' => $old, 'new' => $new]);
        }
        return ['ok' => true, 'signatures' => $new];
    });
}

/* ===== מחירון חדש, תאריך, מחיקה ===== */

/**
 * מחירון חדש מהעתק של קיים, באותה קבוצה: השורות, המחירים, החתימות וכותרת המשנה.
 * raise: {"<pid של שורת section/band>": %, "fee": %} — שורת item מקבלת את האחוז של הכותרת העליונה האחרונה שמעליה,
 * שורת fee את האחוז של "fee". מחיר חדש = round(old × (1 + p/100)); ריק נשאר ריק.
 */
function gp_edition_create(int $uid, array $in): array
{
    $date = gp_date($in['effective_from'] ?? null);
    $base = gp_edition_row((string)($in['base_pid'] ?? ''));
    $aud = $base['audience'];
    $st = db()->prepare("SELECT pid FROM rows WHERE edition_id = ? AND kind IN ('" . implode("','", GP_TOPS) . "')");
    $st->execute([$base['id']]);
    $keys = array_merge($st->fetchAll(PDO::FETCH_COLUMN), ['fee']);
    $raise = [];
    foreach ((array)($in['raise'] ?? []) as $k => $v) {
        if (!in_array((string)$k, $keys, true)) throw new ApiError('אחוז לשורה לא מוכרת');
        if (is_string($v)) $v = trim($v) === '' ? 0 : trim($v);
        if (is_bool($v) || !is_numeric($v) || $v <= -100 || $v > 1000) throw new ApiError('אחוז לא תקין — מספר בין ‎-99 ל-1000');
        $raise[(string)$k] = (float)$v;
    }
    gp_date_free($date, $aud);

    return gp_tx(function (PDO $pdo) use ($uid, $date, $base, $aud, $raise) {
        $rows = $pdo->prepare('SELECT r.*, p.amount FROM rows r LEFT JOIN prices p ON p.row_id = r.id AND p.audience = ?
            WHERE r.edition_id = ? ORDER BY r.sort, r.id');
        $rows->execute([$aud, $base['id']]);
        $list = [];
        $top = null;
        foreach ($rows->fetchAll() as $r) {
            if (in_array($r['kind'], GP_TOPS, true)) $top = $r['pid'];
            $amt = $r['amount'] === null ? null : (int)$r['amount'];
            $key = match ($r['kind']) { 'item' => $top, 'fee' => 'fee', default => null };
            $pct = $key !== null ? ($raise[$key] ?? 0.0) : 0.0;
            if ($amt !== null && $pct != 0) {
                $amt = (int)round($amt * (1 + $pct / 100));
                if ($amt > GP_MAX_AMOUNT) throw new ApiError('אחרי ההעלאה יש מחיר מעל 10,000,000');
            }
            $list[] = ['kind' => $r['kind'], 'indent' => (int)$r['indent'], 'label' => $r['label'], 'price' => $amt];
        }
        $id = gp_insert_edition($pdo, $aud, $date, $base['subtitle'], $base['signatures'], $list);
        $pdo->prepare('UPDATE editions SET created_by = ? WHERE id = ?')->execute([$uid ?: null, $id]);
        gp_audit($uid, 'edition-create', $id, ['effective_from' => $date, 'base' => $base['effective_from'], 'base_pid' => $base['pid'],
            'raise' => array_filter($raise)]);
        return ['ok' => true, 'pid' => (string)$pdo->query("SELECT pid FROM editions WHERE id = $id")->fetchColumn()];
    });
}

/**
 * תאריך — רק למחירון עתידי, ייחודי בתוך הקבוצה. כותרת המשנה (subtitle) — לכל מחירון, ובמחירון שבתוקף רק עם אישור (409).
 */
function gp_edition_update(int $uid, array $in): array
{
    $e = gp_edition_row((string)($in['pid'] ?? ''));
    $date = null;
    if (array_key_exists('effective_from', $in) && $in['effective_from'] !== $e['effective_from']) {
        if (!gp_is_future($e)) throw new ApiError('אפשר לשנות תאריך רק למחירון שעוד לא נכנס לתוקף', 403);
        $date = gp_date($in['effective_from']);
        gp_date_free($date, $e['audience'], (int)$e['id']);
    }
    $sub = null;
    if (array_key_exists('subtitle', $in)) {
        $sub = gp_text($in['subtitle'], 120, 'כותרת המשנה');
        if ($sub === '') throw new ApiError('צריך כותרת משנה');
        if ($sub === $e['subtitle']) $sub = null;
        else gp_guard($e, $in);
    }
    return gp_tx(function (PDO $pdo) use ($uid, $e, $date, $sub) {
        if ($date !== null) {
            $pdo->prepare('UPDATE editions SET effective_from = ?, updated_at = ? WHERE id = ?')->execute([$date, now_iso(), $e['id']]);
            gp_audit($uid, 'edition-update', (int)$e['id'], ['old' => $e['effective_from'], 'new' => $date]);
        }
        if ($sub !== null) {
            $pdo->prepare('UPDATE editions SET subtitle = ?, updated_at = ? WHERE id = ?')->execute([$sub, now_iso(), $e['id']]);
            gp_audit($uid, 'subtitle', (int)$e['id'], ['old' => $e['subtitle'], 'new' => $sub]);
        }
        return ['ok' => true];
    });
}

/** רק מחירון עתידי (טעות בפתיחה). מחירון שהיה בתוקף — לא נמחק לעולם. היומן שומר עותק מלא. */
function gp_edition_delete(int $uid, array $in): array
{
    $e = gp_edition_row((string)($in['pid'] ?? ''));
    if (!gp_is_future($e)) throw new ApiError('מחירון שכבר היה בתוקף לא נמחק', 403);
    $copy = gp_edition($e['pid']);
    foreach ($copy['rows'] as &$r) unset($r['html'], $r['priced']);
    unset($r, $copy['audience_name']);
    return gp_tx(function (PDO $pdo) use ($uid, $e, $copy) {
        $copy['other_prices'] = $pdo->query('SELECT r.pid, p.audience, p.amount FROM prices p JOIN rows r ON r.id = p.row_id
            WHERE r.edition_id = ' . (int)$e['id'] . " AND p.audience <> " . $pdo->quote($e['audience']) . ' AND p.amount IS NOT NULL')->fetchAll();
        $pdo->prepare('DELETE FROM prices WHERE row_id IN (SELECT id FROM rows WHERE edition_id = ?)')->execute([$e['id']]);
        $pdo->prepare('DELETE FROM rows WHERE edition_id = ?')->execute([$e['id']]);
        $pdo->prepare('DELETE FROM editions WHERE id = ?')->execute([$e['id']]);
        gp_audit($uid, 'edition-delete', (int)$e['id'], ['effective_from' => $e['effective_from'], 'copy' => $copy]);
        return ['ok' => true];
    });
}

/* ===== היומן של מחירון ===== */

function gp_history(string $pid): array
{
    $e = gp_edition_row($pid);
    $st = db()->prepare("SELECT a.at, a.action, a.details, u.name AS user FROM audit a LEFT JOIN users u ON u.id = a.user_id
        WHERE a.edition_id = ? AND a.action <> 'print' ORDER BY a.id DESC LIMIT 500");
    $st->execute([$e['id']]);
    return ['history' => array_map(fn($a) => ['at' => $a['at'], 'action' => $a['action'], 'user' => $a['user'] ?? '—',
        'details' => json_decode($a['details'], true) ?: []], $st->fetchAll())];
}
