<?php
/**
 * ⏱ הקצב של צ'אט אחד — ציבורי, לקריאה בלבד (ב-"public" של creative-suite/app.json).
 * GET cadence.php?chat=<role> → {"minutes":N,"paused":bool}. שום מידע נוסף.
 * העריכה — במסך cadence-edit.php, לבעל המערכת בלבד.
 */
declare(strict_types=1);
require __DIR__ . '/lib/cadence.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$role = (string)($_GET['chat'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,40}$/', $role)) $role = '';
echo json_encode(cadence_for(cadence_load(), $role));
