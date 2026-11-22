<?php
/**
 * ניהול המנהלים (אפיון 01 §6): רשימה, השעיה, ביטול השעיה. כל המנהלים בהרשאות מלאות.
 *   - תמיד נשאר מנהל פעיל אחד לפחות, ומנהל לא משעה את עצמו.
 *   - ההשעיה חלה בבקשה הבאה של אותו אדם (sf_authenticate בודק status בכל בקשה) — וה-sessions שלו נמחקים מיד.
 *   - השעיה כאן אינה נוגעת בהרשאה בפלטפורמה; מי שהושעה נשאר חסום גם כשהוא נכנס שוב.
 * השינוי והשורה ביומן — באותה טרנזקציה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/auth.php';

function sf_staff_list(int $me): array
{
    $rows = sf_rows("SELECT * FROM staff ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, name");
    return ['staff' => array_map(fn($s) => sf_staff_public($s, $me), $rows)];
}

/** $actor: id המנהל שמבצע, או null (המערכת). */
function sf_staff_set_status(mixed $pid, string $to, ?int $actor): array
{
    if (!sf_is_pid($pid) || !in_array($to, ['active', 'suspended'], true)) sf_fail('bad_input');
    return sf_tx(function () use ($pid, $to, $actor) {
        $s = sf_row('SELECT * FROM staff WHERE pid = ?', [$pid]) ?? sf_fail('not_found');
        if ($s['status'] === $to) sf_fail('state');
        if ($to === 'suspended') {
            if ($actor !== null && (int)$s['id'] === $actor) sf_fail('self_suspend');
            $others = (int)sf_val("SELECT COUNT(*) FROM staff WHERE status = 'active' AND id <> ?", [$s['id']]);
            if ($others < 1) sf_fail('last_admin');
        }
        sf_exec('UPDATE staff SET status = ?, updated_at = ? WHERE id = ?', [$to, sf_now(), $s['id']]);
        if ($to === 'suspended') sf_exec('DELETE FROM staff_sessions WHERE staff_id = ?', [$s['id']]);
        sf_audit($to === 'suspended' ? 'staff.suspend' : 'staff.unsuspend', 'staff', $s['pid'], ['from' => $s['status'], 'to' => $to], actor: $actor);
        return ['staff' => sf_staff_public(sf_row('SELECT * FROM staff WHERE id = ?', [$s['id']]), $actor)];
    });
}
