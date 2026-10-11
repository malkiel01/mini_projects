<?php
/**
 * רשימת האפליקציות והקטגוריות לדף הראשי.
 * שלב 1: פתוח לכולם, כמו הדף עצמו. הסינון לפי משתמש נכנס בשלב 2–3.
 */
require __DIR__ . '/lib/registry.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$registry = registry_load();
echo json_encode([
    'categories' => registry_categories(),
    'apps'       => $registry['apps'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
