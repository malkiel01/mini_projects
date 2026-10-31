<?php
// זמני: בעל המערכת ביקש (2026-10-31) — הרשמה בלי אישור: כל מי שירצה נרשם ונכנס אחרי אימות המייל.
$p = new PDO('sqlite:platform/data/platform.sqlite');
$p->exec('PRAGMA busy_timeout = 5000');
$before = $p->query("SELECT value FROM settings WHERE key = 'require_approval'")->fetchColumn();
$p->prepare("INSERT INTO settings (key, value) VALUES ('require_approval', '0') ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute();
$after = $p->query("SELECT value FROM settings WHERE key = 'require_approval'")->fetchColumn();
$grants = $p->query("SELECT value FROM settings WHERE key = 'default_grants'")->fetchColumn();
$pending = (int)$p->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
echo json_encode(['before' => $before, 'after' => $after, 'default_grants' => $grants, 'pending_users' => $pending], JSON_UNESCAPED_UNICODE);
