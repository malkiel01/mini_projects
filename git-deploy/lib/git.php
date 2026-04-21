<?php
/**
 * Git operations for clone/pull with token authentication.
 * Sets HOME explicitly because Apache PHP env doesn't include it.
 */

class Git {
    private $token;
    private $home;

    public function __construct($token) {
        $this->token = $token;
        $home = getenv('HOME');
        if (!$home) {
            $u = function_exists('posix_getpwuid') ? @posix_getpwuid(posix_geteuid()) : null;
            $home = $u['dir'] ?? sys_get_temp_dir();
        }
        $this->home = $home;
    }

    /**
     * Run a git command in $cwd.
     * Returns ['ok'=>bool, 'output'=>string, 'exit'=>int, 'cmd'=>string]
     * The cmd field is sanitized: token is replaced with [TOKEN].
     */
    private function run($cmd, $cwd = null) {
        $env = [
            'HOME' => $this->home,
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'GIT_TERMINAL_PROMPT' => '0',
            'LANG' => 'en_US.UTF-8',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            return ['ok' => false, 'output' => 'failed to start process', 'exit' => -1, 'cmd' => $this->scrub($cmd)];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($proc);
        $output = trim($stdout . "\n" . $stderr);
        return [
            'ok' => $exit === 0,
            'output' => $this->scrub($output),
            'exit' => $exit,
            'cmd' => $this->scrub($cmd),
        ];
    }

    private function scrub($text) {
        if (!$this->token) return $text;
        return str_replace($this->token, '***TOKEN***', $text);
    }

    /**
     * Build https://TOKEN@github.com/full_name.git URL for fetching.
     */
    private function authUrl($fullName) {
        return 'https://x-access-token:' . $this->token . '@github.com/' . $fullName . '.git';
    }

    public function isGitDir($path) {
        return is_dir($path) && is_dir($path . '/.git');
    }

    public function dirEmpty($path) {
        if (!is_dir($path)) return true;
        $entries = @scandir($path);
        if ($entries === false) return false;
        foreach ($entries as $e) {
            if ($e !== '.' && $e !== '..') return false;
        }
        return true;
    }

    /**
     * Clone or pull. Decides automatically.
     */
    public function deploy($fullName, $branch, $targetPath) {
        $targetPath = rtrim($targetPath, '/');
        $authUrl = $this->authUrl($fullName);

        if ($this->isGitDir($targetPath)) {
            // existing repo: fetch + checkout + reset to remote branch
            $steps = [];
            $steps[] = $this->run('git remote set-url origin ' . escapeshellarg($authUrl), $targetPath);
            // Use explicit refspec so the remote-tracking ref is updated too (old git doesn't do this implicitly).
            $refspec = '+refs/heads/' . $branch . ':refs/remotes/origin/' . $branch;
            $steps[] = $this->run('git fetch origin ' . escapeshellarg($refspec), $targetPath);
            if (!end($steps)['ok']) return $this->summarize('fetch failed', $steps);
            // Check out the branch (create local tracking branch if missing)
            $steps[] = $this->run('git checkout ' . escapeshellarg($branch) . ' 2>&1 || git checkout -b ' . escapeshellarg($branch) . ' origin/' . escapeshellarg($branch), $targetPath);
            // Reset to the freshly-fetched tip via FETCH_HEAD (works on git 1.8+).
            $steps[] = $this->run('git reset --hard FETCH_HEAD', $targetPath);
            // Restore origin url to non-tokenized to avoid leaking token in .git/config
            $steps[] = $this->run('git remote set-url origin ' . escapeshellarg('https://github.com/' . $fullName . '.git'), $targetPath);
            $steps[] = $this->run('git rev-parse HEAD', $targetPath);
            $steps[] = $this->run('git log -1 --pretty=format:%s', $targetPath);
            return $this->summarize('updated', $steps);
        }

        // not a repo: must clone
        if (!is_dir($targetPath)) {
            if (!@mkdir($targetPath, 0755, true)) {
                return ['ok' => false, 'output' => 'cannot create directory ' . $targetPath, 'steps' => []];
            }
        } elseif (!$this->dirEmpty($targetPath)) {
            return ['ok' => false, 'output' => 'target directory exists and is not empty: ' . $targetPath, 'steps' => []];
        }

        $steps = [];
        $steps[] = $this->run('git clone --branch ' . escapeshellarg($branch) . ' ' . escapeshellarg($authUrl) . ' .', $targetPath);
        if (!end($steps)['ok']) {
            // try clone without branch if branch arg was wrong
            $steps[] = $this->run('git clone ' . escapeshellarg($authUrl) . ' .', $targetPath);
            if (end($steps)['ok']) {
                $steps[] = $this->run('git checkout ' . escapeshellarg($branch), $targetPath);
            }
        }
        $steps[] = $this->run('git remote set-url origin ' . escapeshellarg('https://github.com/' . $fullName . '.git'), $targetPath);
        $steps[] = $this->run('git rev-parse HEAD', $targetPath);
        $steps[] = $this->run('git log -1 --pretty=format:%s', $targetPath);
        return $this->summarize('cloned', $steps);
    }

    public function status($targetPath) {
        if (!$this->isGitDir($targetPath)) {
            return ['ok' => false, 'output' => 'not a git repo'];
        }
        $branch = $this->run('git rev-parse --abbrev-ref HEAD', $targetPath);
        $hash = $this->run('git rev-parse HEAD', $targetPath);
        $msg = $this->run('git log -1 --pretty=format:%s', $targetPath);
        return [
            'ok' => true,
            'branch' => trim($branch['output']),
            'hash' => substr(trim($hash['output']), 0, 12),
            'message' => trim($msg['output']),
        ];
    }

    private function summarize($label, $steps) {
        $ok = true;
        foreach ($steps as $s) { if (!$s['ok']) { $ok = false; break; } }
        $out = '';
        $hash = '';
        $msg = '';
        foreach ($steps as $s) {
            $out .= '$ ' . $s['cmd'] . "\n" . $s['output'] . "\n\n";
        }
        // last two steps are rev-parse HEAD and log -1 by convention
        $n = count($steps);
        if ($n >= 2 && $steps[$n-2]['ok']) $hash = substr(trim($steps[$n-2]['output']), 0, 12);
        if ($n >= 1 && $steps[$n-1]['ok']) $msg = trim($steps[$n-1]['output']);
        return [
            'ok' => $ok,
            'label' => $label,
            'output' => $out,
            'hash' => $hash,
            'message' => $msg,
            'steps' => $steps,
        ];
    }
}
