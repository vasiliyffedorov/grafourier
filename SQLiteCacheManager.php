<?php
require_once __DIR__ . '/Logger.php';

class SQLiteCacheManager {
    private $db;
    private $logger;
    private $maxTtl;
    private $config;

    public function __construct(string $dbPath, Logger $logger, int $maxTtl = 86400, array $config = []) {
        $this->logger = $logger;
        $this->maxTtl = $maxTtl;
        $this->config = $config;
        try {
            $isNewDb = !file_exists($dbPath);
            $this->db = new PDO("sqlite:" . $dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if ($isNewDb) {
                $this->initializeDatabase();
                $this->logger->info("Инициализирована новая база данных кэша SQLite: $dbPath", __FILE__, __LINE__);
            } else {
                $this->checkAndMigrateDatabase();
            }
        } catch (PDOException $e) {
            $this->logger->error("Ошибка базы данных: " . $e->getMessage(), __FILE__, __LINE__);
            throw new Exception("Не удалось инициализировать кэш SQLite");
        }
    }

    private function initializeDatabase(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS queries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query TEXT NOT NULL UNIQUE,
                custom_params TEXT,
                config_hash TEXT,
                last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS dft_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query_id INTEGER NOT NULL,
                metric_hash TEXT NOT NULL,
                metric_json TEXT NOT NULL,
                data_start INTEGER,
                step INTEGER,
                total_duration INTEGER,
                dft_rebuild_count INTEGER DEFAULT 0,
                labels_json TEXT,
                created_at INTEGER,
                anomaly_stats_json TEXT,
                dft_upper_json TEXT,
                dft_lower_json TEXT,
                upper_trend_json TEXT,
                lower_trend_json TEXT,
                last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (query_id) REFERENCES queries(id),
                UNIQUE(query_id, metric_hash)
            )
        ");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_queries_query ON queries(query)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dft_cache_query_id ON dft_cache(query_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dft_cache_metric_hash ON dft_cache(metric_hash)");
    }

    private function checkAndMigrateDatabase(): void {
        $result = $this->db->query("PRAGMA table_info(queries)");
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);
        $hasCustomParams = false;
        $hasConfigHash = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'custom_params') {
                $hasCustomParams = true;
            }
            if ($column['name'] === 'config_hash') {
                $hasConfigHash = true;
            }
        }

        if (!$hasCustomParams) {
            $this->logger->warn("Добавление столбца custom_params в таблицу queries.", __FILE__, __LINE__);
            $this->db->exec("ALTER TABLE queries ADD COLUMN custom_params TEXT");
            $this->logger->info("Столбец custom_params добавлен в таблицу queries.", __FILE__, __LINE__);
        }

        if (!$hasConfigHash) {
            $this->logger->warn("Добавление столбца config_hash в таблицу queries.", __FILE__, __LINE__);
            $this->db->exec("ALTER TABLE queries ADD COLUMN config_hash TEXT");
            $this->logger->info("Столбец config_hash добавлен в таблицу queries.", __FILE__, __LINE__);
        }

        $result = $this->db->query("PRAGMA table_info(dft_cache)");
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);
        $hasUpperTrend = false;
        $hasLowerTrend = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'upper_trend_json') {
                $hasUpperTrend = true;
            }
            if ($column['name'] === 'lower_trend_json') {
                $hasLowerTrend = true;
            }
        }

        if (!$hasUpperTrend) {
            $this->logger->warn("Добавление столбца upper_trend_json в таблицу dft_cache.", __FILE__, __LINE__);
            $this->db->exec("ALTER TABLE dft_cache ADD COLUMN upper_trend_json TEXT");
            $this->logger->info("Столбец upper_trend_json добавлен в таблицу dft_cache.", __FILE__, __LINE__);
        }

        if (!$hasLowerTrend) {
            $this->logger->warn("Добавление столбца lower_trend_json в таблицу dft_cache.", __FILE__, __LINE__);
            $this->db->exec("ALTER TABLE dft_cache ADD COLUMN lower_trend_json TEXT");
            $this->logger->info("Столбец lower_trend_json добавлен в таблицу dft_cache.", __FILE__, __LINE__);
        }
    }

    public function generateCacheKey(string $query, string $labelsJson): string {
        return md5($query . $labelsJson);
    }

    public function saveToCache(string $query, string $labelsJson, array $data, array $currentConfig): bool {
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
            }
            $currentConfigHash = $this->createConfigHash($currentConfig);
            $queryId = $this->getOrCreateQueryId($query, null, $currentConfigHash);
            $metricHash = $this->generateCacheKey($query, $labelsJson);
            $meta = $data['meta'] ?? [];

            $stmt = $this->db->prepare(
                "INSERT OR REPLACE INTO dft_cache 
                (query_id, metric_hash, metric_json, data_start, step, total_duration, 
                 dft_rebuild_count, labels_json, created_at, 
                 anomaly_stats_json, dft_upper_json, dft_lower_json, 
                 upper_trend_json, lower_trend_json, last_accessed) 
                VALUES (:query_id, :metric_hash, :metric_json, :data_start, :step, 
                        :total_duration, :dft_rebuild_count, :labels_json, 
                        :created_at, :anomaly_stats_json, :dft_upper_json, :dft_lower_json, 
                        :upper_trend_json, :lower_trend_json, CURRENT_TIMESTAMP)"
            );
            $stmt->execute([
                ':query_id' => $queryId,
                ':metric_hash' => $metricHash,
                ':metric_json' => $labelsJson,
                ':data_start' => $meta['dataStart'] ?? null,
                ':step' => $meta['step'] ?? null,
                ':total_duration' => $meta['totalDuration'] ?? null,
                ':dft_rebuild_count' => $meta['dft_rebuild_count'] ?? 0,
                ':labels_json' => json_encode($meta['labels'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':created_at' => $meta['created_at'] ?? time(),
                ':anomaly_stats_json' => json_encode($meta['anomaly_stats'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':dft_upper_json' => json_encode($data['dft_upper']['coefficients'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':dft_lower_json' => json_encode($data['dft_lower']['coefficients'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':upper_trend_json' => json_encode($data['dft_upper']['trend'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':lower_trend_json' => json_encode($data['dft_lower']['trend'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]);
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
            $this->logger->info("Сохранен кэш для запроса: $query, dft_rebuild_count: {$meta['dft_rebuild_count']}, config_hash: $currentConfigHash", __FILE__, __LINE__);
            return true;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error("Не удалось сохранить в кэш SQLite: " . $e->getMessage(), __FILE__, __LINE__);
            return false;
        }
    }

    public function loadFromCache(string $query, string $labelsJson): ?array {
        try {
            $metricHash = $this->generateCacheKey($query, $labelsJson);
            $stmt = $this->db->prepare(
                "SELECT 
                    q.id, q.config_hash, dc.data_start, dc.step, dc.total_duration, 
                    dc.dft_rebuild_count, dc.labels_json, dc.created_at, 
                    dc.anomaly_stats_json, dc.dft_upper_json, dc.dft_lower_json, 
                    dc.upper_trend_json, dc.lower_trend_json, dc.last_accessed 
                FROM queries q 
                JOIN dft_cache dc ON q.id = dc.query_id 
                WHERE q.query = :query AND dc.metric_hash = :metric_hash"
            );
            $stmt->execute([':query' => $query, ':metric_hash' => $metricHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $this->updateLastAccessedIfNeeded($row['id'], $metricHash, $row['last_accessed']);
            return [
                'meta' => [
                    'dataStart' => $row['data_start'],
                    'step' => $row['step'],
                    'totalDuration' => $row['total_duration'],
                    'config_hash' => $row['config_hash'],
                    'dft_rebuild_count' => $row['dft_rebuild_count'],
                    'query' => $query,
                    'labels' => json_decode($row['labels_json'], true) ?? [],
                    'created_at' => $row['created_at'],
                    'anomaly_stats' => json_decode($row['anomaly_stats_json'], true) ?? []
                ],
                'dft_upper' => [
                    'coefficients' => json_decode($row['dft_upper_json'], true) ?? [],
                    'trend' => json_decode($row['upper_trend_json'], true) ?? ['slope' => 0, 'intercept' => 0]
                ],
                'dft_lower' => [
                    'coefficients' => json_decode($row['dft_lower_json'], true) ?? [],
                    'trend' => json_decode($row['lower_trend_json'], true) ?? ['slope' => 0, 'intercept' => 0]
                ]
            ];
        } catch (PDOException $e) {
            $this->logger->error("Не удалось загрузить из кэша SQLite: " . $e->getMessage(), __FILE__, __LINE__);
            return null;
        }
    }

    public function getAllCachedMetrics(string $query): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT 
                    q.id, q.config_hash, dc.metric_json, dc.data_start, dc.step, dc.total_duration, 
                    dc.dft_rebuild_count, dc.labels_json, dc.created_at, 
                    dc.anomaly_stats_json, dc.dft_upper_json, dc.dft_lower_json, 
                    dc.upper_trend_json, dc.lower_trend_json, dc.last_accessed 
                FROM queries q 
                JOIN dft_cache dc ON q.id = dc.query_id 
                WHERE q.query = :query"
            );
            $stmt->execute([':query' => $query]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results = [];

            foreach ($rows as $row) {
                $labelsJson = $row['metric_json'];
                $this->updateLastAccessedIfNeeded($row['id'], $this->generateCacheKey($query, $labelsJson), $row['last_accessed']);
                $results[$labelsJson] = [
                    'meta' => [
                        'dataStart' => $row['data_start'],
                        'step' => $row['step'],
                        'totalDuration' => $row['total_duration'],
                        'config_hash' => $row['config_hash'],
                        'dft_rebuild_count' => $row['dft_rebuild_count'],
                        'query' => $query,
                        'labels' => json_decode($row['labels_json'], true) ?? [],
                        'created_at' => $row['created_at'],
                        'anomaly_stats' => json_decode($row['anomaly_stats_json'], true) ?? []
                    ],
                    'dft_upper' => [
                        'coefficients' => json_decode($row['dft_upper_json'], true) ?? [],
                        'trend' => json_decode($row['upper_trend_json'], true) ?? ['slope' => 0, 'intercept' => 0]
                    ],
                    'dft_lower' => [
                        'coefficients' => json_decode($row['dft_lower_json'], true) ?? [],
                        'trend' => json_decode($row['lower_trend_json'], true) ?? ['slope' => 0, 'intercept' => 0]
                    ]
                ];
            }

            return $results;
        } catch (PDOException $e) {
            $this->logger->error("Не удалось загрузить все кэшированные метрики: " . $e->getMessage(), __FILE__, __LINE__);
            return [];
        }
    }

    public function checkCacheExists(string $query, string $labelsJson): bool {
        try {
            $metricHash = $this->generateCacheKey($query, $labelsJson);
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) 
                FROM queries q 
                JOIN dft_cache dc ON q.id = dc.query_id 
                WHERE q.query = :query AND dc.metric_hash = :metric_hash"
            );
            $stmt->execute([':query' => $query, ':metric_hash' => $metricHash]);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            $this->logger->error("Не удалось проверить наличие кэша SQLite: " . $e->getMessage(), __FILE__, __LINE__);
            return false;
        }
    }

    public function shouldRecreateCache(string $query, string $labelsJson, array $currentConfig): bool {
        try {
            $metricHash = $this->generateCacheKey($query, $labelsJson);
            $stmt = $this->db->prepare(
                "SELECT q.config_hash, dc.created_at, dc.labels_json, dc.anomaly_stats_json, dc.dft_rebuild_count 
                FROM queries q 
                JOIN dft_cache dc ON q.id = dc.query_id 
                WHERE q.query = :query AND dc.metric_hash = :metric_hash"
            );
            $stmt->execute([':query' => $query, ':metric_hash' => $metricHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !isset($row['config_hash'])) {
                return true;
            }

            $labels = json_decode($row['labels_json'], true);
            if (isset($labels['unused_metric']) && $labels['unused_metric'] === 'true') {
                $age = time() - $row['created_at'];
                if ($age <= $this->maxTtl) {
                    return false;
                }
            }

            $currentConfigHash = $this->createConfigHash($currentConfig);
            if ($row['config_hash'] !== $currentConfigHash) {
                $this->logger->info("Конфигурация изменилась для запроса: $query. Текущий хеш: $currentConfigHash, сохраненный: {$row['config_hash']}. Требуется пересоздание кэша.", __FILE__, __LINE__);
                return true;
            }
            $age = time() - $row['created_at'];
            if ($age > $this->maxTtl) {
                $this->logger->info("Кэш устарел для запроса: $query. Возраст: $age секунд", __FILE__, __LINE__);
                return true;
            }

            if ($row['dft_rebuild_count'] > ($this->config['cache']['max_rebuild_count'] ?? 10)) {
                $this->logger->warn("Высокое значение dft_rebuild_count ({$row['dft_rebuild_count']}) для запроса: $query, метрика: $labelsJson. Возможен конфликт конфигураций.", __FILE__, __LINE__);
            }

            return false;
        } catch (PDOException $e) {
            $this->logger->error("Не удалось проверить необходимость пересоздания кэша SQLite: " . $e->getMessage(), __FILE__, __LINE__);
            return true;
        }
    }

    public function cleanupOldEntries(int $maxAgeDays = 30): void {
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-$maxAgeDays days"));
            $this->db->exec("DELETE FROM dft_cache WHERE last_accessed < '$cutoff'");
            $this->db->exec(
                "DELETE FROM queries 
                WHERE id NOT IN (SELECT DISTINCT query_id FROM dft_cache) 
                AND last_accessed < '$cutoff'"
            );
            $this->logger->info("Очищены старые записи кэша старше $maxAgeDays дней", __FILE__, __LINE__);
        } catch (PDOException $e) {
            $this->logger->error("Не удалось очистить старые записи кэша SQLite: " . $e->getMessage(), __FILE__, __LINE__);
        }
    }

    public function getOrCreateQueryId(string $query, ?string $customParams = null, ?string $configHash = null): int {
        try {
            $inTransaction = $this->db->inTransaction();
            if (!$inTransaction) {
                $this->db->beginTransaction();
            }

            $stmt = $this->db->prepare("SELECT id, last_accessed, custom_params, config_hash FROM queries WHERE query = :query");
            $stmt->execute([':query' => $query]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $this->updateLastAccessedQueryIfNeeded($row['id'], $row['last_accessed']);
                if ($customParams !== null || $configHash !== null) {
                    $this->updateQueryParams($row['id'], $customParams ?? $row['custom_params'], $configHash ?? $row['config_hash']);
                }
                if (!$inTransaction) {
                    $this->db->commit();
                }
                return $row['id'];
            }

            $stmt = $this->db->prepare("INSERT INTO queries (query, custom_params, config_hash) VALUES (:query, :custom_params, :config_hash)");
            $stmt->execute([
                ':query' => $query,
                ':custom_params' => $customParams,
                ':config_hash' => $configHash
            ]);
            $queryId = $this->db->lastInsertId();
            if (!$inTransaction) {
                $this->db->commit();
            }
            $this->logger->info("Создана новая запись в queries для запроса: $query, query_id: $queryId", __FILE__, __LINE__);
            return $queryId;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error("Ошибка при получении или создании query_id для запроса: $query, ошибка: " . $e->getMessage(), __FILE__, __LINE__);
            throw new Exception("Не удалось получить или создать query_id");
        }
    }

    public function getCustomParams(string $query): ?string {
        try {
            $stmt = $this->db->prepare("SELECT custom_params FROM queries WHERE query = :query");
            $stmt->execute([':query' => $query]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row['custom_params'] ?? null;
        } catch (PDOException $e) {
            $this->logger->error("Не удалось получить кастомные параметры для запроса: $query, ошибка: " . $e->getMessage(), __FILE__, __LINE__);
            return null;
        }
    }

    public function resetCustomParams(string $query): bool {
        try {
            $inTransaction = $this->db->inTransaction();
            if (!$inTransaction) {
                $this->db->beginTransaction();
            }
            $stmt = $this->db->prepare("UPDATE queries SET custom_params = NULL, config_hash = NULL WHERE query = :query");
            $stmt->execute([':query' => $query]);
            if (!$inTransaction) {
                $this->db->commit();
            }
            $this->logger->info("Сброшены кастомные параметры и config_hash для запроса: $query", __FILE__, __LINE__);
            return true;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error("Не удалось сбросить кастомные параметры для запроса: $query, ошибка: " . $e->getMessage(), __FILE__, __LINE__);
            return false;
        }
    }

    private function updateQueryParams(int $queryId, ?string $customParams, ?string $configHash): void {
        try {
            $inTransaction = $this->db->inTransaction();
            if (!$inTransaction) {
                $this->db->beginTransaction();
            }
            $stmt = $this->db->prepare("UPDATE queries SET custom_params = :custom_params, config_hash = :config_hash, last_accessed = CURRENT_TIMESTAMP WHERE id = :query_id");
            $stmt->execute([
                ':custom_params' => $customParams,
                ':config_hash' => $configHash,
                ':query_id' => $queryId
            ]);
            if (!$inTransaction) {
                $this->db->commit();
            }
            $this->logger->info("Обновлены параметры для query_id: $queryId, custom_params: $customParams, config_hash: $configHash", __FILE__, __LINE__);
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error("Не удалось обновить параметры для query_id: $queryId, ошибка: " . $e->getMessage(), __FILE__, __LINE__);
            throw new Exception("Не удалось обновить параметры запроса");
        }
    }

    private function updateLastAccessedIfNeeded(int $queryId, string $metricHash, string $lastAccessed): void {
        $currentHour = date('Y-m-d H:00:00');
        $lastAccessedHour = date('Y-m-d H:00:00', strtotime($lastAccessed));
        if ($currentHour !== $lastAccessedHour) {
            $this->db->prepare(
                "UPDATE queries SET last_accessed = CURRENT_TIMESTAMP WHERE id = :query_id"
            )->execute([':query_id' => $queryId]);
            $this->db->prepare(
                "UPDATE dft_cache SET last_accessed = CURRENT_TIMESTAMP 
                WHERE query_id = :query_id AND metric_hash = :metric_hash"
            )->execute([':query_id' => $queryId, ':metric_hash' => $metricHash]);
        }
    }

    private function updateLastAccessedQueryIfNeeded(int $queryId, string $lastAccessed): void {
        $currentHour = date('Y-m-d H:00:00');
        $lastAccessedHour = date('Y-m-d H:00:00', strtotime($lastAccessed));
        if ($currentHour !== $lastAccessedHour) {
            $this->db->prepare(
                "UPDATE queries SET last_accessed = CURRENT_TIMESTAMP WHERE id = :query_id"
            )->execute([':query_id' => $queryId]);
        }
    }

    public function createConfigHash(array $config): string {
        $standardizedConfig = json_decode(json_encode($config), true);
        array_walk_recursive($standardizedConfig, function (&$value) {
            if (is_float($value)) {
                $value = round($value, 5);
            }
        });
        ksort($standardizedConfig);
        $filteredConfig = array_filter($standardizedConfig, function ($key) {
            return !str_starts_with($key, 'save');
        }, ARRAY_FILTER_USE_KEY);
        return md5(json_encode($filteredConfig));
    }
}
?>