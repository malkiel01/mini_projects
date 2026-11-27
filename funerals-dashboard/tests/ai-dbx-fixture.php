<?php
// פרוסה 06 — Dropbox מחובר לארגון 1 (מול tests/dropbox-mock.php), עם קובץ PDF בתיק $caseId. CLI בלבד.
// php ai-dbx-fixture.php <app-dir> <case-id> <mock-state.json> → מדפיס את מספר שורת הקובץ ב-files
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[, $app, $caseId, $stateFile] = $argv;
chdir($app);
require 'lib.php';
$pdo = db();
secretPut('dropbox_app_key', 0, 'mockkey123');
secretPut('dropbox_app_secret', 0, 'mocksecret456');
secretPut('dropbox_refresh', 1, 'rt_ai_test');
$now = gmdate('c');
$pdo->prepare("INSERT OR REPLACE INTO dbx_orgs(org_id, status, account_id, account_name, root_id, root_path, root_lower, connected_at)
    VALUES (1, 'connected', 'dbid:AAA111', 'חשבון בדיקה', 'id:root', '/Funerals', '/funerals', ?)")->execute([$now]);
$path = '/Funerals/בדיקה/רישיון.pdf';
$pdo->prepare("INSERT INTO files(org_id, case_id, dbx_id, path, path_lower, name, size, rev, server_modified, deleted, added_via, kind, created_at, updated_at)
    VALUES (1, ?, 'id:aipdf1', ?, ?, 'רישיון.pdf', 30, 'r1', ?, 0, 'dropbox', '', ?, ?)")->execute([$caseId, $path, mb_strtolower($path), $now, $now, $now]);
echo $pdo->lastInsertId();
file_put_contents($stateFile, json_encode(['refresh' => ['rt_ai_test' => 'dbid:AAA111'], 'entries' => [
    'id:root' => ['tag' => 'folder', 'path_display' => '/Funerals', 'path_lower' => '/funerals'],
    'id:aipdf1' => ['tag' => 'file', 'path_display' => $path, 'path_lower' => mb_strtolower($path), 'size' => 30, 'rev' => 'r1', 'server_modified' => '2026-11-20T00:00:00Z'],
]], JSON_UNESCAPED_UNICODE));
