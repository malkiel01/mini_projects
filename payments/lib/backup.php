<?php
/**
 * גיבוי יומי של מסד התשלומים (פרק 9.7) — מוצפן במפתח של התוסף (data/secret.key), 30 יום, ב-data/backups/
 * (מוחרג בפריסה). ההורדה — מממשק הניהול בלבד. **את secret.key לגבות בנפרד** — בלעדיו גם הגיבוי לא נפתח.
 * וגם — ייצוא חודשי לרואה החשבון (CSV).
 */

declare(strict_types=1);

require_once __DIR__ . '/util.php';

const BACKUP_KEEP_DAYS = 30;

function backup_dir(): string
{
    $d = pay_data_dir() . '/backups';
    if (!is_dir($d)) mkdir($d, 0700, true);
    return $d;
}

/** פעם ביום (מהשעון). מחזיר את שם הקובץ, או null אם כבר גובה היום. */
function backup_daily(bool $force = false): ?string
{
    $day = gmdate('Ymd', now());
    if (!$force && setting('backup_day') === $day) return null;
    $tmp = backup_dir() . '/.tmp-' . bin2hex(random_bytes(6)) . '.sqlite';
    db()->exec('VACUUM INTO ' . db()->quote($tmp));
    $name = 'payments-' . gmdate('Ymd-His', now()) . '.sqlite.enc';
    file_put_contents(backup_dir() . '/' . $name, seal((string)file_get_contents($tmp)));
    @chmod(backup_dir() . '/' . $name, 0600);
    unlink($tmp);
    set_setting('backup_day', $day);
    foreach (glob(backup_dir() . '/payments-*.sqlite.enc') ?: [] as $f) {
        if (filemtime($f) < now() - BACKUP_KEEP_DAYS * 86400) @unlink($f);
    }
    return $name;
}

function backups_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/payments-*.sqlite.enc') ?: [] as $f) $out[] = ['name' => basename($f), 'size' => filesize($f), 'at' => filemtime($f)];
    usort($out, fn($a, $b) => $b['at'] <=> $a['at']);
    return $out;
}

/** הגיבוי מפוענח (קובץ מסד) — להורדה מממשק הניהול. */
function backup_read(string $name): string
{
    if (!preg_match('/^payments-\d{8}-\d{6}\.sqlite\.enc$/', $name) || !is_file(backup_dir() . '/' . $name)) throw new ApiError('הגיבוי לא נמצא', 'not_found', 404);
    return unseal((string)file_get_contents(backup_dir() . '/' . $name));
}

/** ייצוא חודשי לרואה החשבון: כל תשלום ומסמך, עם מע"מ, אמצעי ומספר המסמך. */
function export_month(array $tenant, string $period, string $env = 'live'): string
{
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) throw new ApiError('חודש בצורה YYYY-MM', 'bad_period');
    $from = strtotime("$period-01 00:00:00 UTC");
    $to = strtotime('+1 month', $from);
    $rows = db_all("SELECT p.*, m.name AS merchant_name, m.tax_status, a.name AS account_name, a.tax_id, i.vat, i.total AS invoice_total,
            (SELECT GROUP_CONCAT(d.type || ':' || d.number, ' ') FROM tax_document d WHERE d.payment = p.id) AS docs,
            (SELECT GROUP_CONCAT(description, ' + ') FROM invoice_line l WHERE l.invoice = p.invoice) AS lines
        FROM payment p JOIN account a ON a.id = p.account JOIN merchant m ON m.id = a.merchant LEFT JOIN invoice i ON i.id = p.invoice
        WHERE m.tenant = ? AND p.env = ? AND p.created_at >= ? AND p.created_at < ? ORDER BY p.created_at", [$tenant['id'], $env, $from, $to]);
    $DOC = ['320' => 'חשבונית מס/קבלה', '400' => 'קבלה', '330' => 'חשבונית זיכוי'];
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, "\xEF\xBB\xBF"); // ‏BOM — אקסל פותח עברית נכון
    fputcsv($fh, ['תאריך', 'עוסק', 'לקוח', 'ח"פ / ע"מ', 'פירוט', 'סכום', 'מע"מ', 'הוחזר', 'מצב', 'אמצעי', 'סולק', 'מסמכים', 'מזהה תשלום']);
    foreach ($rows as $r) {
        $vat = $r['invoice_total'] && (int)$r['invoice_total'] === (int)$r['amount'] ? (int)$r['vat'] : '';
        $docs = implode(' ', array_map(function ($d) use ($DOC) { [$t, $n] = array_pad(explode(':', $d, 2), 2, ''); return ($DOC[$t] ?? $t) . " $n"; }, array_filter(explode(' ', (string)$r['docs']))));
        $provider = $r['provider'] === 'manual' ? 'ידני' : ($r['provider'] === 'fake' ? 'מדומה' : (str_starts_with((string)$r['provider'], 'pa_') ? 'מורנינג' : $r['provider']));
        fputcsv($fh, [
            gmdate('d/m/Y', (int)$r['created_at']), $r['merchant_name'], $r['account_name'], $r['tax_id'], $r['lines'] ?? '',
            number_format((int)$r['amount'] / 100, 2, '.', ''), $vat === '' ? '' : number_format($vat / 100, 2, '.', ''),
            number_format((int)$r['refunded'] / 100, 2, '.', ''), $r['status'], $r['pay_method'] ?: 'כרטיס', $provider, $docs, $r['id'],
        ]);
    }
    rewind($fh);
    return (string)stream_get_contents($fh);
}
