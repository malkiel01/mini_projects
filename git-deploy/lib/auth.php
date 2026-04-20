<?php
/**
 * Authentication: password + IP whitelist + session.
 */

require_once __DIR__ . '/config.php';

class Auth {
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_name('GITDEPLOYSESS');
            session_start();
        }
    }

    public static function clientIp() {
        $candidates = [];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidates[] = trim($parts[0]);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) $candidates[] = $_SERVER['HTTP_X_REAL_IP'];
        if (!empty($_SERVER['REMOTE_ADDR'])) $candidates[] = $_SERVER['REMOTE_ADDR'];
        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function ipAllowed($ip = null) {
        if ($ip === null) $ip = self::clientIp();
        $allowed = Config::get('settings')['allowed_ips'] ?? [];
        if (empty($allowed)) return true;
        foreach ($allowed as $entry) {
            if (self::ipMatch($ip, $entry)) return true;
        }
        return false;
    }

    private static function ipMatch($ip, $rule) {
        $rule = trim($rule);
        if ($rule === '' || $rule === '*') return true;
        if ($rule === $ip) return true;
        if (strpos($rule, '/') !== false) {
            return self::cidrMatch($ip, $rule);
        }
        if (substr($rule, -2) === '.*') {
            $prefix = substr($rule, 0, -2);
            return strpos($ip, $prefix . '.') === 0;
        }
        return false;
    }

    private static function cidrMatch($ip, $cidr) {
        list($subnet, $bits) = explode('/', $cidr);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $ipL = ip2long($ip);
        $subnetL = ip2long($subnet);
        $mask = -1 << (32 - (int)$bits);
        return ($ipL & $mask) === ($subnetL & $mask);
    }

    public static function isLoggedIn() {
        self::start();
        return !empty($_SESSION['authed']) && !empty($_SESSION['ip_at_login']) && $_SESSION['ip_at_login'] === self::clientIp();
    }

    public static function login($password) {
        if (!self::ipAllowed()) return ['ok' => false, 'reason' => 'ip_blocked'];
        $hash = Config::get('settings')['password_hash'] ?? '';
        if (!$hash) return ['ok' => false, 'reason' => 'not_configured'];
        if (!password_verify($password, $hash)) return ['ok' => false, 'reason' => 'bad_password'];
        self::start();
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        $_SESSION['ip_at_login'] = self::clientIp();
        $_SESSION['login_time'] = time();
        return ['ok' => true];
    }

    public static function logout() {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function require_() {
        if (!Config::exists()) {
            header('Location: setup.php');
            exit;
        }
        if (!self::ipAllowed()) {
            http_response_code(403);
            echo '<h1>403 - IP not allowed</h1><p>Your IP <code>' . htmlspecialchars(self::clientIp()) . '</code> is not in the whitelist.</p>';
            exit;
        }
        if (!self::isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function csrfToken() {
        self::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function csrfCheck($token) {
        self::start();
        return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$token);
    }
}
