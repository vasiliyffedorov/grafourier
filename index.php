<?php
declare(strict_types=1);
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/CacheManagerFactory.php';
require_once __DIR__ . '/CorridorBuilder.php';
require_once __DIR__ . '/GrafanaProxyClient.php';

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

/** Нормализация и разделение override-параметров */
function normalizeQuery(string $q): string {
    return trim(preg_replace('/\s+/', ' ', str_replace(["\r","\n"], '', $q)));
}

function parseValue(string $v): mixed {
    // булевы
    if (strcasecmp($v, 'true') === 0) return true;
    if (strcasecmp($v, 'false') === 0) return false;
    // массив по запятой
    if (str_contains($v, ',')) {
        return array_map('trim', explode(',', $v));
    }
    // число
    if (is_numeric($v)) {
        return str_contains($v, '.')
            ? (float)$v
            : (int)$v;
    }
    // строка
    return $v;
}

function setNested(array &$cfg, array $keys, $value): void {
    $ref = &$cfg;
    foreach ($keys as $i => $key) {
        if ($i === count($keys) - 1) {
            $ref[$key] = $value;
        } else {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
    }
}

function sendJson(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function jsonSuccess($data, int $code = 200): void {
    sendJson(['status' => 'success', 'data' => $data], $code);
}

function jsonError(string $msg, int $code = 400): void {
    sendJson(['status' => 'error', 'error' => $msg], $code);
}

// 1) читаем конфиг
$flatIni = parse_ini_file(__DIR__.'/config.cfg', true, INI_SCANNER_RAW);
if ($flatIni === false) {
    throw new Exception("Не удалось прочитать config.cfg");
}
function nest(array $flatEntries): array {
    $out = [];
    foreach ($flatEntries as $key => $value) {
        $parts = explode('.', $key);
        $ref = &$out;
        foreach ($parts as $i => $part) {
            if ($i === count($parts)-1) {
                // списки через запятую
                if (str_contains($value, ',')) {
                    $ref[$part] = array_map('trim', explode(',', $value));
                } elseif (is_numeric($value)) {
                    $ref[$part] = str_contains($value, '.') ? (float)$value : (int)$value;
                } elseif (strtolower($value)==='true') {
                    $ref[$part] = true;
                } elseif (strtolower($value)==='false') {
                    $ref[$part] = false;
                } else {
                    $ref[$part] = $value;
                }
            } else {
                if (!isset($ref[$part]) || !is_array($ref[$part])) {
                    $ref[$part] = [];
                }
                $ref = &$ref[$part];
            }
        }
    }
    return $out;
}

$config = nest($flatIni);
$logger = new Logger(
    $config['log_file'],
    Logger::{"LEVEL_" . strtoupper($config['log_level'] ?? 'INFO')}
);

// 2) Grafana client
$proxy = new GrafanaProxyClient(
    $config['grafana_url'],
    $config['grafana_api_token'],
    $logger,
    $config['blacklist_datasource_ids'] ?? [] // Pass blacklist_datasource_ids to GrafanaProxyClient
);

// 3) роутинг
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// GET /api/v1/labels
if ($method==='GET' && $path==='/api/v1/labels') {
    jsonSuccess($proxy->getMetricNames());
    exit;
}

// GET /api/v1/label/__name__/values
if ($method==='GET' && $path==='/api/v1/label/__name__/values') {
    jsonSuccess($proxy->getMetricNames());
    exit;
}

// POST /api/v1/query_range
if ($method==='POST' && $path==='/api/v1/query_range') {
    $params = [];
    parse_str(file_get_contents('php://input'), $params);
    if (empty($params['query'])) {
        jsonError('Missing query', 400);
    }

    // разбираем override-параметры
    $normalizedQuery = normalizeQuery($params['query']);
    $overrides = [];
    if (str_contains($normalizedQuery, '#')) {
        list($cleanQuery, $overrideString) = explode('#', $normalizedQuery, 2);
        $params['query'] = trim($cleanQuery);
        foreach (explode(';', $overrideString) as $overrideChunk) {
            if (!str_contains($overrideChunk, '=')) continue;
            list($k, $v) = array_map('trim', explode('=', $overrideChunk, 2));
            $overrides[$k] = $v;
        }
    }

    // накладываем их на копию конфига
    $finalConfig = $config;
    foreach ($overrides as $overrideKey => $overrideValue) {
        $keys = explode('.', $overrideKey);
        setNested($finalConfig, $keys, parseValue($overrideValue));
    }

    // параметры запроса
    $start = (int)($params['start'] ?? time()-3600);
    $end   = (int)($params['end']   ?? time());
    $step  = (int)($params['step']  ?? 60);

    // и строим коридор
    $corridorBuilder = new CorridorBuilder(
        $config['grafana_url'],
        $logger,
        $finalConfig
    );
    $result = $corridorBuilder->build(
        $params['query'],
        $start,
        $end,
        $step
    );

    echo json_encode($result);
    exit;
}

// всё остальное 404
jsonError('Not found', 404);
?>