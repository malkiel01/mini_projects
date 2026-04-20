<?php
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/github.php';
require_once __DIR__ . '/lib/git.php';

header('Content-Type: application/json; charset=utf-8');

function jr($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!Config::exists()) jr(['ok' => false, 'error' => 'not_configured'], 403);
if (!Auth::ipAllowed()) jr(['ok' => false, 'error' => 'ip_blocked'], 403);
if (!Auth::isLoggedIn()) jr(['ok' => false, 'error' => 'not_logged_in'], 401);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$cfg = Config::load();
$token = $cfg['settings']['github_token'] ?? '';

// CSRF check for state-changing actions
$stateChanging = ['deploy', 'add_path', 'remove_path'];
if (in_array($action, $stateChanging, true)) {
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Auth::csrfCheck($sent)) jr(['ok' => false, 'error' => 'csrf'], 403);
}

switch ($action) {

case 'refresh_repos': {
    if (!$token) jr(['ok' => false, 'error' => 'no_token']);
    $gh = new GitHub($token);
    $r = $gh->myRepos();
    if (!$r['ok']) jr(['ok' => false, 'error' => 'github', 'detail' => $r['body']['message'] ?? '']);
    $cfg['repos_cache'] = ['fetched_at' => time(), 'data' => $r['body']];
    Config::save($cfg);
    jr(['ok' => true, 'count' => count($r['body']), 'repos' => $r['body'], 'fetched_at' => $cfg['repos_cache']['fetched_at']]);
}

case 'list_branches': {
    $repo = $_GET['repo'] ?? '';
    if (!$repo) jr(['ok' => false, 'error' => 'missing_repo']);
    if (!$token) jr(['ok' => false, 'error' => 'no_token']);
    $gh = new GitHub($token);
    $r = $gh->branches($repo);
    if (!$r['ok']) jr(['ok' => false, 'error' => 'github', 'detail' => $r['body']['message'] ?? '']);
    jr(['ok' => true, 'branches' => $r['body']]);
}

case 'deploy': {
    $repo = $_POST['repo'] ?? '';
    $branch = $_POST['branch'] ?? '';
    $path = trim($_POST['path'] ?? '');
    if (!$repo || !$branch || !$path) jr(['ok' => false, 'error' => 'missing_fields']);
    if (!$token) jr(['ok' => false, 'error' => 'no_token']);

    $git = new Git($token);
    $result = $git->deploy($repo, $branch, $path);

    // remember in config
    if (!isset($cfg['repositories'][$repo])) {
        $cfg['repositories'][$repo] = ['paths' => []];
    }
    $entry = [
        'path' => $path,
        'branch' => $branch,
        'last_pulled' => date('Y-m-d H:i:s'),
        'last_commit_hash' => $result['hash'] ?? '',
        'last_commit_msg' => $result['message'] ?? '',
        'last_status' => $result['ok'] ? 'success' : 'failed',
    ];

    // upsert by (path)
    $existing = &$cfg['repositories'][$repo]['paths'];
    $found = false;
    foreach ($existing as $i => $p) {
        if (($p['path'] ?? '') === $path) {
            $existing[$i] = $entry;
            $found = true;
            break;
        }
    }
    if (!$found) $existing[] = $entry;

    // log
    if (!isset($cfg['logs'])) $cfg['logs'] = [];
    array_unshift($cfg['logs'], [
        'time' => date('Y-m-d H:i:s'),
        'repo' => $repo,
        'branch' => $branch,
        'path' => $path,
        'status' => $result['ok'] ? 'success' : 'failed',
    ]);
    $cfg['logs'] = array_slice($cfg['logs'], 0, 200);

    Config::save($cfg);

    jr([
        'ok' => $result['ok'],
        'output' => $result['output'] ?? '',
        'hash' => $result['hash'] ?? '',
        'message' => $result['message'] ?? '',
        'entry' => $entry,
    ]);
}

case 'remove_path': {
    $repo = $_POST['repo'] ?? '';
    $path = $_POST['path'] ?? '';
    if (!$repo || !$path) jr(['ok' => false, 'error' => 'missing_fields']);
    if (isset($cfg['repositories'][$repo]['paths'])) {
        $cfg['repositories'][$repo]['paths'] = array_values(array_filter(
            $cfg['repositories'][$repo]['paths'],
            function ($p) use ($path) { return ($p['path'] ?? '') !== $path; }
        ));
        Config::save($cfg);
    }
    jr(['ok' => true]);
}

case 'add_path': {
    // alias of deploy - same logic
    $repo = $_POST['repo'] ?? '';
    $branch = $_POST['branch'] ?? '';
    $path = trim($_POST['path'] ?? '');
    if (!$repo || !$branch || !$path) jr(['ok' => false, 'error' => 'missing_fields']);
    if (!$token) jr(['ok' => false, 'error' => 'no_token']);

    $git = new Git($token);
    $result = $git->deploy($repo, $branch, $path);

    if (!isset($cfg['repositories'][$repo])) $cfg['repositories'][$repo] = ['paths' => []];
    $entry = [
        'path' => $path,
        'branch' => $branch,
        'last_pulled' => date('Y-m-d H:i:s'),
        'last_commit_hash' => $result['hash'] ?? '',
        'last_commit_msg' => $result['message'] ?? '',
        'last_status' => $result['ok'] ? 'success' : 'failed',
    ];
    $existing = &$cfg['repositories'][$repo]['paths'];
    $found = false;
    foreach ($existing as $i => $p) {
        if (($p['path'] ?? '') === $path) { $existing[$i] = $entry; $found = true; break; }
    }
    if (!$found) $existing[] = $entry;

    if (!isset($cfg['logs'])) $cfg['logs'] = [];
    array_unshift($cfg['logs'], [
        'time' => date('Y-m-d H:i:s'), 'repo' => $repo, 'branch' => $branch,
        'path' => $path, 'status' => $result['ok'] ? 'success' : 'failed',
    ]);
    $cfg['logs'] = array_slice($cfg['logs'], 0, 200);
    Config::save($cfg);

    jr([
        'ok' => $result['ok'],
        'output' => $result['output'] ?? '',
        'hash' => $result['hash'] ?? '',
        'message' => $result['message'] ?? '',
        'entry' => $entry,
    ]);
}

default:
    jr(['ok' => false, 'error' => 'unknown_action'], 400);
}
