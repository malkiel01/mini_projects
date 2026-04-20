<?php
/**
 * Config storage - reads/writes data/config.json
 */

class Config {
    private static $path = null;
    private static $data = null;

    private static function path() {
        if (self::$path === null) {
            self::$path = dirname(__DIR__) . '/data/config.json';
        }
        return self::$path;
    }

    public static function exists() {
        return file_exists(self::path());
    }

    public static function load() {
        if (self::$data !== null) return self::$data;
        if (!self::exists()) {
            self::$data = self::defaults();
            return self::$data;
        }
        $raw = file_get_contents(self::path());
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::$data = self::defaults();
        } else {
            self::$data = array_replace_recursive(self::defaults(), $decoded);
        }
        return self::$data;
    }

    public static function save($data = null) {
        if ($data !== null) self::$data = $data;
        $dir = dirname(self::path());
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $json = json_encode(self::$data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp = self::path() . '.tmp';
        file_put_contents($tmp, $json, LOCK_EX);
        @chmod($tmp, 0600);
        rename($tmp, self::path());
        return true;
    }

    public static function get($key, $default = null) {
        $data = self::load();
        return $data[$key] ?? $default;
    }

    public static function set($key, $value) {
        self::load();
        self::$data[$key] = $value;
        self::save();
    }

    private static function defaults() {
        return [
            'settings' => [
                'password_hash' => '',
                'allowed_ips' => [],
                'github_token' => '',
                'github_user' => '',
                'created_at' => null,
            ],
            'repositories' => [],
            'repos_cache' => [
                'fetched_at' => 0,
                'data' => [],
            ],
            'logs' => [],
        ];
    }

    public static function reset() {
        self::$data = null;
        if (file_exists(self::path())) @unlink(self::path());
    }
}
