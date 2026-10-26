<?php
/**
 * אימות מייל בהרשמה, והגדרת "הרשמה דורשת אישור מנהל".
 *
 * הזרימה: הרשמה (שם, מייל, סיסמה) → מייל עם קישור (#verify=<אסימון>, 48 שעות, שימוש אחד) →
 * המייל אומת → אם ההרשמה דורשת אישור (ברירת המחדל): ממתין למנהל, והמנהל מקבל התראה; אחרת — פעיל.
 * ההתראה למנהל נשלחת רק אחרי האימות, כדי שהרשמות מזויפות לא יציפו אותו.
 *
 * השליחה ב-mail() של השרת. PLATFORM_MAIL_SINK (משתנה סביבה) — לבדיקות: המיילים נכתבים לקובץ.
 */

declare(strict_types=1);

const VERIFY_HOURS = 48;

// ---------- הגדרות ----------

function setting(string $key, string $default = ''): string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function setting_set(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

/** האם הרשמה חדשה ממתינה לאישור המנהל (גם אחרי אימות המייל). ברירת מחדל: כן. */
function approval_required(): bool
{
    return setting('require_approval', '1') === '1';
}

// ---------- מייל ----------

function validate_email($e): string
{
    $e = is_string($e) ? mb_strtolower(trim($e)) : '';
    if (strlen($e) > 200 || filter_var($e, FILTER_VALIDATE_EMAIL) === false) {
        throw new ApiError('כתובת המייל לא תקינה', 422);
    }
    return $e;
}

function send_mail(string $to, string $subject, string $body): bool
{
    $sink = getenv('PLATFORM_MAIL_SINK');
    if ($sink) {
        return (bool)file_put_contents($sink, json_encode(['to' => $to, 'subject' => $subject, 'body' => $body],
            JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $headers = implode("\r\n", [
        'From: =?UTF-8?B?' . base64_encode('כלים דיגיטליים') . "?= <no-reply@$host>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ]);
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($body)), $headers);
}

/** כתובת דף הבית של הפלטפורמה (https://host/<base>/). */
function site_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . cookie_path();
}

// ---------- אימות ----------

/** אסימון חדש (הקודמים של המשתמש נמחקים) ומייל עם הקישור. */
function verify_send(int $userId, string $email, string $name, string $after = ''): bool
{
    $token = bin2hex(random_bytes(24));
    db()->prepare('DELETE FROM email_tokens WHERE user_id = ?')->execute([$userId]);
    db()->prepare('INSERT INTO email_tokens (token_hash, user_id, expires_at) VALUES (?,?,?)')
        ->execute([hash('sha256', $token), $userId, gmdate('Y-m-d\TH:i:s\Z', time() + VERIFY_HOURS * 3600)]);
    $next = $after !== '' ? $after : (approval_required() ? 'אחרי האימות, המנהל יאשר את החשבון.' : 'אחרי האימות החשבון פעיל.');
    return send_mail($email, 'אימות כתובת המייל — כלים דיגיטליים',
        "שלום $name,\n\nכדי להשלים את ההרשמה לכלים הדיגיטליים, לחצו על הקישור:\n" . site_url() . "#verify=$token\n\n" .
        "הקישור בתוקף " . VERIFY_HOURS . " שעות. $next\n\nאם לא נרשמתם — אפשר להתעלם מהמייל הזה.");
}

/**
 * מממש אסימון: המייל אומת. בלי צורך באישור — החשבון נפתח. מחזיר את המשתמש (אחרי העדכון).
 * אסימון שגוי, שפג או שכבר נוצל — שגיאה.
 */
function verify_consume(string $token): array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) throw new ApiError('הקישור לא תקין', 400);
    $st = db()->prepare('SELECT user_id, expires_at FROM email_tokens WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch();
    if (!$row) throw new ApiError('הקישור כבר נוצל או שאינו תקין', 410);
    db()->prepare('DELETE FROM email_tokens WHERE user_id = ?')->execute([(int)$row['user_id']]);
    if ($row['expires_at'] < now_iso()) throw new ApiError('תוקף הקישור פג — היכנסו ובקשו מייל חדש', 410);

    $u = db()->prepare('SELECT * FROM users WHERE id = ?');
    $u->execute([(int)$row['user_id']]);
    $user = $u->fetch();
    if (!$user) throw new ApiError('החשבון לא נמצא', 404);
    $status = $user['status'] === 'pending' && !approval_required() ? 'active' : $user['status'];
    db()->prepare('UPDATE users SET email_verified_at = ?, status = ? WHERE id = ?')
        ->execute([now_iso(), $status, (int)$user['id']]);
    return ['email_verified_at' => now_iso(), 'status' => $status] + $user;
}
