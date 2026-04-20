<?php
/**
 * GitHub API client - minimal: validate token, list user repos.
 */

class GitHub {
    private $token;
    public function __construct($token) { $this->token = $token; }

    private function request($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_USERAGENT => 'git-deploy-mini/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $this->token,
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => 'curl: ' . $err, 'status' => 0];
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        curl_close($ch);

        $headers = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (strpos($line, ':') !== false) {
                list($k, $v) = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        $json = json_decode($body, true);
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $json, 'raw' => $body, 'headers' => $headers];
    }

    public function me() {
        return $this->request('https://api.github.com/user');
    }

    public function myRepos() {
        $repos = [];
        $url = 'https://api.github.com/user/repos?per_page=100&affiliation=owner&sort=updated&direction=desc';
        $page = 1;
        while ($url) {
            $r = $this->request($url);
            if (!$r['ok']) return $r;
            if (is_array($r['body'])) {
                foreach ($r['body'] as $repo) {
                    $repos[] = [
                        'full_name' => $repo['full_name'],
                        'name' => $repo['name'],
                        'owner' => $repo['owner']['login'] ?? '',
                        'private' => !empty($repo['private']),
                        'default_branch' => $repo['default_branch'] ?? 'main',
                        'description' => $repo['description'] ?? '',
                        'updated_at' => $repo['updated_at'] ?? '',
                        'clone_url' => $repo['clone_url'] ?? '',
                        'html_url' => $repo['html_url'] ?? '',
                    ];
                }
            }
            $url = self::nextLink($r['headers']['link'] ?? '');
            $page++;
            if ($page > 20) break; // safety
        }
        return ['ok' => true, 'status' => 200, 'body' => $repos];
    }

    public function branches($fullName) {
        $url = 'https://api.github.com/repos/' . $fullName . '/branches?per_page=100';
        $branches = [];
        while ($url) {
            $r = $this->request($url);
            if (!$r['ok']) return $r;
            if (is_array($r['body'])) {
                foreach ($r['body'] as $b) {
                    $branches[] = $b['name'];
                }
            }
            $url = self::nextLink($r['headers']['link'] ?? '');
        }
        return ['ok' => true, 'status' => 200, 'body' => $branches];
    }

    private static function nextLink($linkHeader) {
        if (!$linkHeader) return null;
        $links = explode(',', $linkHeader);
        foreach ($links as $link) {
            if (preg_match('/<([^>]+)>;\s*rel="next"/', $link, $m)) {
                return $m[1];
            }
        }
        return null;
    }
}
