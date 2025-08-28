<?php
require_once __DIR__ . '/SQLiteCacheManager.php';
require_once __DIR__ . '/Logger.php';

class CacheManagerFactory {
    public static function create(array $config, Logger $logger) {
        $dbConfig = $config['cache']['database'];
        if (!extension_loaded('pdo_sqlite')) {
            throw new Exception("PDO SQLite extension is not loaded");
        }
        return new SQLiteCacheManager(
            $dbConfig['path'],
            $logger,
            $dbConfig['max_ttl'] ?? 86400,
            $config
        );
    }
}
?>