<?php
/**
 * הגדרות ה-AI של הבעלים.
 *
 * המפתח נשמר מוצפן (ai_key_enc) ומפוענח רק ברגע הפנייה למודל. שאר
 * המערכת מקבלת אובייקט חיבור מוכן ולא נוגעת במפתח הגלוי.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/ai.php';

/** מחזיר את הגדרות ה-AI לתצוגה — בלי המפתח עצמו, רק זנב לזיהוי. */
function aiSettingsView(): array {
    $o = owner();
    $key = decryptSecret($o['ai_key_enc'] ?? '');
    return [
        'provider'  => $o['ai_provider'] ?? 'anthropic',
        'model'     => $o['ai_model'] ?? '',
        'key_tail'  => secretTail($key),
        'has_key'   => $key !== '',
        'providers' => array_map(fn($id, $m) => [
            'id'      => $id,
            'label'   => $m['label'],
            'default' => $m['default'],
            'hint'    => $m['hint'],
            'models'  => $m['models'] ?? [],
        ], array_keys(PROVIDERS), PROVIDERS),
    ];
}

/**
 * טוען את רשימת המודלים החיה מהספק, לפי המפתח השמור. משמש את כפתור
 * "טען מודלים" ב-UI. הבחירה להשתמש במפתח השמור (ולא באחד שנשלח בבקשה)
 * מונעת דליפה של מפתח לא-שמור ומחייבת לשמור אותו קודם — מה שגם מאמת
 * שהוא תקין.
 */
function fetchProviderModels(): array {
    $conn = ownerAiConn();   // זורק AppError אם אין מפתח
    return listProviderModels($conn['provider'], $conn['key']);
}

function saveAiSettings(string $provider, string $model, ?string $key): void {
    if (!providerExists($provider)) throw new InvalidArgumentException('ספק לא מוכר');
    $fields = [
        'ai_provider' => $provider,
        'ai_model'    => mb_substr(trim($model), 0, 80),
    ];
    // מפתח ריק פירושו "אל תיגע במפתח הקיים". החלפה מפורשת בלבד.
    if ($key !== null && $key !== '') {
        checkProviderKey($provider, $key);   // זורק על מפתח שגוי-צורה
        $fields['ai_key_enc'] = encryptSecret($key);
    }
    updateOwner($fields);
}

/** אובייקט החיבור המלא לפנייה — עם המפתח המפוענח. */
function ownerAiConn(): array {
    $o = owner();
    $key = decryptSecret($o['ai_key_enc'] ?? '');
    if ($key === '') throw new AppError('לא הוגדר מפתח AI. הגדר אותו במסך ההגדרות.');
    return [
        'provider' => $o['ai_provider'] ?? 'anthropic',
        'model'    => $o['ai_model'] ?? '',
        'key'      => $key,
    ];
}
